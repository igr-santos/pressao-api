from datetime import UTC, datetime, timedelta
from unittest.mock import patch
from uuid import uuid4

import pytest

from pressao_api.models.acao import Acao
from pressao_api.models.alvo import Alvo
from pressao_api.models.campanha import Campanha
from pressao_api.schemas.acao import CanalEnum, ProximoPassoTipoEnum, StatusAcaoEnum
from pressao_api.schemas.email import ResultadoEnvioEmail
from pressao_api.services.metricas import CalculadoraMetricas
from pressao_api.services.orquestrador import OrquestradorCanais


class TestMetricas:
    """Testes para calculadora de métricas."""

    def test_calcular_qualidade_suspeita(self):
        """Tempo < 5s deve ser suspeita."""
        qualidade = CalculadoraMetricas.calcular_qualidade(3)
        assert qualidade == "suspeita"

    def test_calcular_qualidade_alta(self):
        """Tempo entre 5s e 60s deve ser alta."""
        qualidade = CalculadoraMetricas.calcular_qualidade(30)
        assert qualidade == "alta"

    def test_calcular_qualidade_media(self):
        """Tempo entre 60s e 120s deve ser média."""
        qualidade = CalculadoraMetricas.calcular_qualidade(90)
        assert qualidade == "media"

    def test_calcular_qualidade_baixa(self):
        """Tempo > 120s deve ser baixa."""
        qualidade = CalculadoraMetricas.calcular_qualidade(150)
        assert qualidade == "baixa"

    def test_calcular_tempo_resposta(self):
        """Calcula tempo em segundos corretamente."""
        criado = datetime.now(UTC)
        confirmado = criado + timedelta(seconds=45)
        tempo = CalculadoraMetricas.calcular_tempo_resposta(criado, confirmado)
        assert tempo == 45


class TestOrquestrador:
    """Testes para orquestrador de canais."""

    @pytest.mark.asyncio
    async def test_estrategia_email(self):
        """Testa estratégia de email disparando o EmailService."""
        campanha_id = uuid4()
        acao = Acao(
            id=uuid4(),
            ativista_id="test",
            campanha_id=campanha_id,
            alvo_id=uuid4(),
            canal=CanalEnum.EMAIL,
            ativista_email="ativista@email.com",
            ativista_nome="Ativista Teste",
        )
        alvo = Alvo(
            id=acao.alvo_id,
            nome="Alvo Email",
            contato="alvo@email.com",
            tipo_contato="email",
            campanha_id=campanha_id,
        )
        campanha = Campanha(id=campanha_id, nome="Campanha Email")
        resultado = ResultadoEnvioEmail(
            sucesso=True,
            message_id="sandbox-123",
            sandbox=True,
            status="sandbox",
            destinatario="alvo@email.com",
        )

        orquestrador = OrquestradorCanais()
        with patch(
            "pressao_api.services.orquestrador.email_service.enviar_pressao",
            return_value=resultado,
        ) as mock_enviar:
            await orquestrador.executar(acao, alvo=alvo, campanha=campanha)

        mock_enviar.assert_called_once()
        kwargs = mock_enviar.call_args.kwargs
        assert kwargs["destinatario"] == "alvo@email.com"
        assert kwargs["remetente_email"] == "ativista@email.com"
        assert kwargs["acao_id"] == str(acao.id)
        assert "Pressão" in kwargs["assunto"]
        assert acao.status == StatusAcaoEnum.PROCESSANDO
        assert acao.proximo_passo_tipo == ProximoPassoTipoEnum.WEBHOOK_AGUARDAR
        assert acao.proximo_passo_dados["provider"] == "sendgrid"
        assert acao.proximo_passo_dados["message_id"] == "sandbox-123"

    @pytest.mark.asyncio
    async def test_estrategia_email_sem_alvo_falha(self):
        acao = Acao(
            id=uuid4(),
            ativista_id="test",
            campanha_id=uuid4(),
            alvo_id=uuid4(),
            canal=CanalEnum.EMAIL,
        )
        orquestrador = OrquestradorCanais()
        with pytest.raises(ValueError, match="Alvo com e-mail"):
            await orquestrador.executar(acao)

        assert acao.status == StatusAcaoEnum.FALHA

    @pytest.mark.asyncio
    async def test_estrategia_email_sem_remetente_falha(self):
        campanha_id = uuid4()
        acao = Acao(
            id=uuid4(),
            campanha_id=campanha_id,
            alvo_id=uuid4(),
            canal=CanalEnum.EMAIL,
            anonimo=True,
            ativista_email=None,
        )
        alvo = Alvo(
            id=acao.alvo_id,
            nome="Alvo Email",
            contato="alvo@email.com",
            tipo_contato="email",
            campanha_id=campanha_id,
        )
        orquestrador = OrquestradorCanais()
        with pytest.raises(ValueError, match="e-mail do ativista"):
            await orquestrador.executar(acao, alvo=alvo)

        assert acao.status == StatusAcaoEnum.FALHA

    @pytest.mark.asyncio
    async def test_estrategia_whatsapp(self):
        """Testa estratégia de WhatsApp."""
        acao = Acao(
            id=uuid4(),
            ativista_id="test",
            campanha_id=uuid4(),
            alvo_id=uuid4(),
            canal=CanalEnum.WHATSAPP,
        )

        orquestrador = OrquestradorCanais()
        await orquestrador.executar(acao)

        assert acao.status == StatusAcaoEnum.AGUARDANDO_ACAO_HUMANA
        assert acao.proximo_passo_tipo == ProximoPassoTipoEnum.REDIRECIONAR_LINK
        assert "link" in acao.proximo_passo_dados

    @pytest.mark.asyncio
    async def test_estrategia_instagram(self):
        """Testa estratégia de Instagram."""
        acao = Acao(
            id=uuid4(),
            ativista_id="test",
            campanha_id=uuid4(),
            alvo_id=uuid4(),
            canal=CanalEnum.INSTAGRAM,
        )

        orquestrador = OrquestradorCanais()
        await orquestrador.executar(acao)

        assert acao.status == StatusAcaoEnum.AGUARDANDO_ACAO_HUMANA
        assert acao.proximo_passo_tipo == ProximoPassoTipoEnum.EXIBIR_TEXTO_E_ABRIR_PERFIL
        assert "texto" in acao.proximo_passo_dados

    @pytest.mark.asyncio
    async def test_estrategia_instagram_usa_contato_como_url_postagem(self):
        """Instagram: nome = perfil; contato = URL da postagem."""
        from pressao_api.models.alvo import Alvo, TipoContato

        alvo = Alvo(
            id=uuid4(),
            nome="Deputada Ana",
            contato="https://www.instagram.com/p/AbCdEfGhIjK/",
            tipo_contato=TipoContato.INSTAGRAM,
            campanha_id=uuid4(),
        )
        acao = Acao(
            id=uuid4(),
            ativista_id="test",
            campanha_id=alvo.campanha_id,
            alvo_id=alvo.id,
            canal=CanalEnum.INSTAGRAM,
        )

        orquestrador = OrquestradorCanais()
        await orquestrador.executar(acao, alvo=alvo)

        assert acao.proximo_passo_dados["perfil"] == "Deputada Ana"
        assert (
            acao.proximo_passo_dados["url_postagem"]
            == "https://www.instagram.com/p/AbCdEfGhIjK/"
        )
        assert acao.proximo_passo_dados["url_perfil"] == acao.proximo_passo_dados["url_postagem"]

    @pytest.mark.asyncio
    async def test_estrategia_tiktok_usa_contato_como_url_postagem(self):
        """TikTok: nome = perfil; contato = URL do vídeo."""
        from pressao_api.models.alvo import Alvo, TipoContato

        alvo = Alvo(
            id=uuid4(),
            nome="Perfil Pressão",
            contato="https://www.tiktok.com/@perfil/video/1234567890",
            tipo_contato=TipoContato.TIKTOK,
            campanha_id=uuid4(),
        )
        acao = Acao(
            id=uuid4(),
            ativista_id="test",
            campanha_id=alvo.campanha_id,
            alvo_id=alvo.id,
            canal=CanalEnum.TIKTOK,
        )

        orquestrador = OrquestradorCanais()
        await orquestrador.executar(acao, alvo=alvo)

        assert acao.proximo_passo_dados["perfil"] == "Perfil Pressão"
        assert (
            acao.proximo_passo_dados["url_postagem"]
            == "https://www.tiktok.com/@perfil/video/1234567890"
        )