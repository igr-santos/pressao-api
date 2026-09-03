from datetime import datetime

import structlog
from sqlalchemy.ext.asyncio import AsyncSession

from pressao_api.core.config import settings
from pressao_api.models.acao import Acao
from pressao_api.models.alvo import Alvo
from pressao_api.models.campanha import Campanha
from pressao_api.models.template import Template
from pressao_api.repositories.alvo_membro_repository import AlvoMembroRepository
from pressao_api.repositories.disparo_repository import DisparoRepository
from pressao_api.repositories.template_repository import TemplateRepository
from pressao_api.schemas.acao import CanalEnum, ProximoPassoTipoEnum, StatusAcaoEnum, TipoAcaoEnum
from pressao_api.services.email_service import email_service
from pressao_api.services.templates import sortear_template

logger = structlog.get_logger()


class OrquestradorCanais:
    """Orquestrador de canais para ações de pressão."""

    def __init__(self):
        # Mapeamento de estratégias por canal
        self.estrategias = {
            CanalEnum.EMAIL: self._estrategia_email,
            CanalEnum.TELEFONE: self._estrategia_telefone,
            CanalEnum.WHATSAPP: self._estrategia_whatsapp,
            CanalEnum.INSTAGRAM: self._estrategia_instagram,
            CanalEnum.TIKTOK: self._estrategia_tiktok,
        }

    async def executar(
        self,
        acao: Acao,
        alvo: Alvo | None = None,
        campanha: Campanha | None = None,
        template: Template | None = None,
        session: AsyncSession | None = None,
    ) -> Acao:
        """Executa a estratégia do canal."""
        try:
            canal = CanalEnum(acao.canal)
            estrategia = self.estrategias.get(canal)

            if not estrategia:
                raise ValueError(f"Canal não suportado: {acao.canal}")

            logger.info(
                "Executando ação",
                acao_id=str(acao.id),
                canal=acao.canal,
                tipo_acao=acao.tipo_acao,
            )

            if acao.tipo_acao == TipoAcaoEnum.MULTI_ALVO.value and canal == CanalEnum.EMAIL:
                if session is None:
                    raise ValueError("Sessão de banco obrigatória para ação multi_alvo")
                await self._estrategia_email_multi_alvo(
                    acao, alvo=alvo, campanha=campanha, template=template, session=session
                )
            else:
                await estrategia(acao, alvo=alvo, campanha=campanha, template=template)

            return acao

        except Exception as e:
            logger.error("Erro ao executar ação", error=str(e), acao_id=str(acao.id))
            acao.status = StatusAcaoEnum.FALHA
            raise

    async def _estrategia_email_multi_alvo(
        self,
        acao: Acao,
        alvo: Alvo | None = None,
        campanha: Campanha | None = None,
        template: Template | None = None,
        session: AsyncSession | None = None,
    ):
        """Estratégia para e-mail multi-alvo: N disparos SendGrid, 1 ação."""
        if session is None:
            raise ValueError("Sessão de banco obrigatória para ação multi_alvo")
        if alvo is None:
            raise ValueError("Alvo agregado é obrigatório para ação multi_alvo")

        remetente_email = acao.ativista_email
        remetente_nome = acao.ativista_nome
        if not remetente_email:
            raise ValueError("Canal email exige e-mail do ativista como remetente")

        membro_repo = AlvoMembroRepository(session)
        membros = await membro_repo.listar_membros_alvos(alvo.id)
        if not membros:
            raise ValueError("Nenhum destinatário de e-mail ativo para esta ação")

        disparo_repo = DisparoRepository(session)
        template_repo = TemplateRepository(session)
        templates_email = await template_repo.listar_ativos_por_canal(
            acao.campanha_id, CanalEnum.EMAIL.value
        )

        enviados = 0
        falhas = 0

        for membro in membros:
            tpl = template if template else sortear_template(templates_email)

            disparo = await disparo_repo.criar(
                {
                    "acao_id": acao.id,
                    "alvo_id": membro.id,
                    "status": "ENVIADO",
                }
            )

            html = email_service.montar_template_pressao(
                acao=acao, alvo=membro, campanha=campanha, template=tpl
            )
            if tpl:
                assunto = tpl.titulo
            else:
                assunto = f"Pressão: {campanha.nome}" if campanha else "Mensagem de pressão"

            resultado = email_service.enviar_pressao(
                destinatario=membro.contato,
                remetente_email=remetente_email,
                remetente_nome=remetente_nome,
                assunto=assunto,
                conteudo_html=html,
                acao_id=str(acao.id),
                campanha_id=str(acao.campanha_id),
                nome_destinatario=membro.nome,
                disparo_id=str(disparo.id),
            )

            if resultado.sucesso:
                disparo.status = "ENVIADO"
                disparo.message_id = resultado.message_id
                enviados += 1
            else:
                disparo.status = "ERRO_ENVIO"
                disparo.proximo_passo_dados = {"erro": resultado.erro}
                falhas += 1
            await disparo_repo.salvar(disparo)

        agora = datetime.utcnow()  # noqa: DTZ003
        total = len(membros)
        dados_resumo = {
            "disparos_total": total,
            "disparos_enviados": enviados,
            "disparos_falha": falhas,
        }

        if enviados >= 1:
            acao.status = StatusAcaoEnum.CONCLUIDA
            acao.confirmado_em = agora
            acao.proximo_passo_tipo = ProximoPassoTipoEnum.FINALIZADO
            acao.proximo_passo_instrucao = "E-mails enviados ao SendGrid"
            acao.proximo_passo_dados = dados_resumo
        else:
            acao.status = StatusAcaoEnum.FALHA
            acao.proximo_passo_tipo = ProximoPassoTipoEnum.FINALIZADO
            acao.proximo_passo_instrucao = "Falha ao enviar todos os e-mails"
            acao.proximo_passo_dados = dados_resumo

        logger.info(
            "Ação multi_alvo processada",
            acao_id=str(acao.id),
            enviados=enviados,
            falhas=falhas,
            status=acao.status,
        )

    async def _estrategia_email(
        self,
        acao: Acao,
        alvo: Alvo | None = None,
        campanha: Campanha | None = None,
        template: Template | None = None,
    ):
        """Estratégia para Email (SendGrid). Remetente = ativista; destinatário = alvo."""
        if alvo is None or not alvo.contato:
            raise ValueError("Alvo com e-mail é obrigatório para o canal email")

        remetente_email = acao.ativista_email
        remetente_nome = acao.ativista_nome
        if not remetente_email:
            raise ValueError("Canal email exige e-mail do ativista como remetente")

        html = email_service.montar_template_pressao(
            acao=acao, alvo=alvo, campanha=campanha, template=template
        )
        if template:
            assunto = template.titulo
        else:
            assunto = f"Pressão: {campanha.nome}" if campanha else "Mensagem de pressão"
        resultado = email_service.enviar_pressao(
            destinatario=alvo.contato,
            remetente_email=remetente_email,
            remetente_nome=remetente_nome,
            assunto=assunto,
            conteudo_html=html,
            acao_id=str(acao.id),
            campanha_id=str(acao.campanha_id),
            nome_destinatario=alvo.nome,
        )

        if not resultado.sucesso:
            raise RuntimeError(resultado.erro or "Falha ao enviar e-mail via SendGrid")

        acao.status = StatusAcaoEnum.PROCESSANDO
        acao.proximo_passo_tipo = ProximoPassoTipoEnum.WEBHOOK_AGUARDAR
        acao.proximo_passo_instrucao = "Aguardando confirmação de entrega via webhook"
        acao.proximo_passo_dados = {
            "provider": "sendgrid",
            "message_id": resultado.message_id,
            "sandbox": resultado.sandbox,
            "destinatario": alvo.contato,
            "remetente": remetente_email,
            "evento": "delivered",
            "webhook_url": settings.SENDGRID_WEBHOOK_URL,
            "template_id": str(acao.template_id) if acao.template_id else None,
        }
        logger.info(
            "Email enviado",
            acao_id=str(acao.id),
            message_id=resultado.message_id,
            sandbox=resultado.sandbox,
            template_id=str(acao.template_id) if acao.template_id else None,
        )

    async def _estrategia_telefone(
        self,
        acao: Acao,
        alvo: Alvo | None = None,
        campanha: Campanha | None = None,
        template: Template | None = None,
    ):
        """Estratégia para Telefone (Twilio)."""
        acao.status = StatusAcaoEnum.PROCESSANDO
        acao.proximo_passo_tipo = ProximoPassoTipoEnum.WEBHOOK_AGUARDAR
        acao.proximo_passo_instrucao = "Aguardando confirmação de chamada via webhook"
        acao.proximo_passo_dados = {
            "webhook_url": "https://api.twilio.com/v3/webhook",
            "evento": "call_completed",
        }
        logger.info("Chamada telefônica iniciada", acao_id=str(acao.id))

    async def _estrategia_whatsapp(
        self,
        acao: Acao,
        alvo: Alvo | None = None,
        campanha: Campanha | None = None,
        template: Template | None = None,
    ):
        """Estratégia para WhatsApp (Manual)."""
        acao.status = StatusAcaoEnum.AGUARDANDO_ACAO_HUMANA
        acao.proximo_passo_tipo = ProximoPassoTipoEnum.REDIRECIONAR_LINK
        acao.proximo_passo_instrucao = "Clique no link para enviar a mensagem no WhatsApp"
        acao.proximo_passo_dados = {
            "link": "https://wa.me/5511999999999?text=Ol%C3%A1%2C%20esta%20%C3%A9%20uma%20mensagem%20de%20press%C3%A3o",
            "texto": "Olá, esta é uma mensagem de pressão sobre o tema X",
        }
        logger.info("Link WhatsApp gerado", acao_id=str(acao.id))

    async def _estrategia_instagram(
        self,
        acao: Acao,
        alvo: Alvo | None = None,
        campanha: Campanha | None = None,
        template: Template | None = None,
    ):
        """Estratégia para Instagram (Manual).

        Convenção do alvo:
        - ``nome``: nome do perfil a ser comentado
        - ``contato``: URL da postagem/vídeo a abrir após copiar o texto
        """
        nome_perfil = alvo.nome if alvo and alvo.nome else ""
        url_postagem = self._resolver_url_postagem_social(
            alvo.contato if alvo else None,
            base_url="https://instagram.com",
        )
        acao.status = StatusAcaoEnum.AGUARDANDO_ACAO_HUMANA
        acao.proximo_passo_tipo = ProximoPassoTipoEnum.EXIBIR_TEXTO_E_ABRIR_PERFIL
        acao.proximo_passo_instrucao = (
            "Copie o texto e comente na postagem do Instagram"
        )
        acao.proximo_passo_dados = {
            "perfil": nome_perfil,
            "texto": self._montar_texto_social(
                acao,
                alvo=alvo,
                campanha=campanha,
                template=template,
                padrao=(
                    "Olá, esta é uma mensagem de pressão sobre o tema X. "
                    "Por favor, considere nossa demanda."
                ),
            ),
            "url_postagem": url_postagem,
            # BC: frontends antigos liam url_perfil; agora aponta para a postagem
            "url_perfil": url_postagem,
            "template_id": str(acao.template_id) if acao.template_id else None,
        }
        logger.info("Texto Instagram gerado", acao_id=str(acao.id))

    async def _estrategia_tiktok(
        self,
        acao: Acao,
        alvo: Alvo | None = None,
        campanha: Campanha | None = None,
        template: Template | None = None,
    ):
        """Estratégia para TikTok (Manual), equivalente ao fluxo de Instagram.

        Convenção do alvo:
        - ``nome``: nome do perfil a ser comentado
        - ``contato``: URL do vídeo/postagem a abrir após copiar o texto
        """
        nome_perfil = alvo.nome if alvo and alvo.nome else ""
        url_postagem = self._resolver_url_postagem_social(
            alvo.contato if alvo else None,
            base_url="https://www.tiktok.com",
            prefixo_usuario="@",
        )
        acao.status = StatusAcaoEnum.AGUARDANDO_ACAO_HUMANA
        acao.proximo_passo_tipo = ProximoPassoTipoEnum.EXIBIR_TEXTO_E_ABRIR_PERFIL
        acao.proximo_passo_instrucao = "Copie o texto e comente no vídeo do TikTok"
        acao.proximo_passo_dados = {
            "perfil": nome_perfil,
            "texto": self._montar_texto_social(
                acao,
                alvo=alvo,
                campanha=campanha,
                template=template,
                padrao=(
                    "Olá, esta é uma mensagem de pressão sobre o tema X. "
                    "Por favor, considere nossa demanda."
                ),
            ),
            "url_postagem": url_postagem,
            # BC: frontends antigos liam url_perfil; agora aponta para a postagem
            "url_perfil": url_postagem,
            "template_id": str(acao.template_id) if acao.template_id else None,
        }
        logger.info("Texto TikTok gerado", acao_id=str(acao.id))

    def _montar_texto_social(
        self,
        acao: Acao,
        alvo: Alvo | None,
        campanha: Campanha | None,
        template: Template | None,
        padrao: str,
    ) -> str:
        """Aplica os placeholders conhecidos ao texto social sorteado."""
        texto = template.conteudo if template else padrao
        valores = {
            "alvo_nome": alvo.nome if alvo else "",
            "campanha_nome": campanha.nome if campanha else "Campanha de pressão",
            "ativista_nome": "" if acao.anonimo else (acao.ativista_nome or ""),
            "acao_id": str(acao.id),
        }
        for chave, valor in valores.items():
            texto = texto.replace("{" + chave + "}", valor)
        return texto

    def _resolver_url_postagem_social(
        self,
        contato: str | None,
        base_url: str,
        prefixo_usuario: str = "",
    ) -> str:
        """Resolve o link da postagem/vídeo a partir de ``alvo.contato``.

        Preferência: URL completa (http/https). Fallback legado: trata handle
        ``@usuario`` como perfil na base do canal.
        """
        if not contato:
            return ""
        contato = contato.strip()
        if contato.startswith(("http://", "https://")):
            return contato
        return self._montar_url_social(contato, base_url, prefixo_usuario)

    def _montar_url_social(self, perfil: str, base_url: str, prefixo_usuario: str = "") -> str:
        """Transforma @usuario em URL e preserva URLs já configuradas."""
        perfil = perfil.strip()
        if perfil.startswith(("http://", "https://")):
            return perfil
        return f"{base_url}/{prefixo_usuario}{perfil.lstrip('@')}"


orquestrador = OrquestradorCanais()
