from datetime import datetime
from enum import Enum
from typing import Any

from pydantic import UUID4, BaseModel, Field, field_validator, model_validator

from pressao_api.utils.validadores import validar_email, validar_telefone


class CanalEnum(str, Enum):
    EMAIL = "email"
    TELEFONE = "telefone"
    WHATSAPP = "whatsapp"
    INSTAGRAM = "instagram"
    TIKTOK = "tiktok"


class StatusAcaoEnum(str, Enum):
    PROCESSANDO = "PROCESSANDO"
    AGUARDANDO_ACAO_HUMANA = "AGUARDANDO_ACAO_HUMANA"
    CONCLUIDA = "CONCLUIDA"
    FALHA = "FALHA"


class ProximoPassoTipoEnum(str, Enum):
    WEBHOOK_AGUARDAR = "WEBHOOK_AGUARDAR"
    REDIRECIONAR_LINK = "REDIRECIONAR_LINK"
    EXIBIR_TEXTO_E_ABRIR_PERFIL = "EXIBIR_TEXTO_E_ABRIR_PERFIL"
    FINALIZADO = "FINALIZADO"


class MetricaQualidadeEnum(str, Enum):
    SUSPEITA = "suspeita"
    ALTA = "alta"
    MEDIA = "media"
    BAIXA = "baixa"


class TipoAcaoEnum(str, Enum):
    SIMPLES = "simples"
    MULTI_ALVO = "multi_alvo"


class DisparosResumoResponse(BaseModel):
    total: int = 0
    enviados: int = 0
    entregues: int = 0
    falhas: int = 0


# Request schemas


class AtivistaInfo(BaseModel):
    nome: str | None = Field(None, max_length=200, description="Nome completo do ativista")
    email: str | None = Field(None, max_length=200, description="Email do ativista")
    telefone: str | None = Field(None, max_length=20, description="Telefone do ativista")

    @field_validator("email")
    @classmethod
    def validate_email(cls, v: str | None) -> str | None:
        if v and not validar_email(v):
            raise ValueError("Formato de e-mail inválido")
        return v

    @field_validator("telefone")
    @classmethod
    def validate_telefone(cls, v: str | None) -> str | None:
        if v and not validar_telefone(v):
            raise ValueError("Formato de telefone inválido. Use: (11) 99999-9999 ou 11999999999")
        return v


class CriarAcaoRequest(BaseModel):
    campanha_id: UUID4
    alvo_id: UUID4
    canal: CanalEnum
    template_id: UUID4 | None = None

    ativista: AtivistaInfo | None = None
    anonimo: bool = False
    sessao_id: str | None = Field(
        None, max_length=36, description="Identificador único da sessão do navegador (UUID v4)"
    )

    @field_validator("sessao_id")
    @classmethod
    def validate_sessao_id(cls, v: str | None) -> str | None:
        if v:
            import re

            uuid_pattern = re.compile(
                r"^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$",
                re.IGNORECASE,
            )
            if not uuid_pattern.match(v):
                raise ValueError("sessao_id deve ser um UUID v4 válido")
        return v

    @model_validator(mode="after")
    def validate_identificacao(self) -> "CriarAcaoRequest":
        """Valida identificação do ativista quando fornecida."""
        if self.anonimo:
            return self

        if not self.ativista:
            return self

        if not self.ativista.email and not self.ativista.telefone:
            raise ValueError("É necessário fornecer email ou telefone do ativista")

        return self


# Response schemas
class ProximoPassoResponse(BaseModel):
    tipo: ProximoPassoTipoEnum
    instrucao: str
    dados: dict[str, Any]


class RespostaAcaoResponse(BaseModel):
    acao_id: UUID4
    ativista_id: str | None = None
    ativista_nome: str | None = None
    ativista_email: str | None = None
    ativista_telefone: str | None = None
    anonimo: bool = False  # ← IMPORTANTE!
    campanha_id: UUID4
    alvo_id: UUID4
    tipo_acao: TipoAcaoEnum = TipoAcaoEnum.SIMPLES
    status_atual: StatusAcaoEnum
    proximo_passo: ProximoPassoResponse
    disparos_resumo: DisparosResumoResponse | None = None


class AcaoDetailResponse(BaseModel):
    id: UUID4
    ativista_id: str | None = None
    ativista_nome: str | None = None
    ativista_email: str | None = None
    ativista_telefone: str | None = None
    anonimo: bool = False
    campanha_id: UUID4
    alvo_id: UUID4
    tipo_acao: TipoAcaoEnum = TipoAcaoEnum.SIMPLES
    canal: str
    template_id: UUID4 | None
    status: StatusAcaoEnum
    metrica_qualidade: MetricaQualidadeEnum | None
    tempo_resposta_seg: int | None
    confirmado_em: datetime | None
    criado_em: datetime
    atualizado_em: datetime

    class Config:
        from_attributes = True


class AcaoStatusResponse(BaseModel):
    id: UUID4
    status: StatusAcaoEnum
    metrica_qualidade: MetricaQualidadeEnum | None
    confirmado_em: datetime | None
