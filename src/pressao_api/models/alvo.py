import enum
import uuid
from datetime import datetime

from sqlalchemy import JSON, Boolean, Column, DateTime, Enum, ForeignKey, String
from sqlalchemy.dialects.postgresql import UUID

from pressao_api.core.database import Base


class TipoContato(str, enum.Enum):
    EMAIL = "email"
    TELEFONE = "telefone"
    WHATSAPP = "whatsapp"
    INSTAGRAM = "instagram"
    TIKTOK = "tiktok"


class ModoAlvo(str, enum.Enum):
    INDIVIDUAL = "individual"
    AGREGADO = "agregado"


def _enum_valores(enum_class: type[enum.Enum]) -> list[str]:
    return [member.value for member in enum_class]


class Alvo(Base):
    __tablename__ = "alvos"

    id = Column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    nome = Column(String(200), nullable=False)
    contato = Column(String(500), nullable=False, index=True)
    tipo_contato = Column(Enum(TipoContato), nullable=False)
    modo = Column(
        Enum(ModoAlvo, name="modoalvo", values_callable=_enum_valores),
        nullable=False,
        default=ModoAlvo.INDIVIDUAL,
        server_default=ModoAlvo.INDIVIDUAL.value,
    )

    campanha_id = Column(
        UUID(as_uuid=True), ForeignKey("campanhas.id", ondelete="CASCADE"), nullable=False
    )

    metadados = Column(JSON, nullable=True)  # Para dados extras: cargo, rede social, etc.
    ativo = Column(Boolean, default=True)

    criado_em = Column(DateTime, default=datetime.utcnow, nullable=False)
    atualizado_em = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)

    # Relacionamento com campanha
    # campanha = relationship("Campanha", back_populates="alvos")

    def __repr__(self):
        return f"<Alvo {self.nome} ({self.contato})>"
