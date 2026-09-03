"""widen alvos.contato for social post URLs

Revision ID: a7b8c9d0e1f2
Revises: f6a7b8c9d0e1
Create Date: 2026-09-03 14:30:00.000000

"""

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "a7b8c9d0e1f2"
down_revision: str | Sequence[str] | None = "f6a7b8c9d0e1"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    """Instagram/TikTok passam a guardar URL de postagem/vídeo em contato."""
    op.alter_column(
        "alvos",
        "contato",
        existing_type=sa.String(length=200),
        type_=sa.String(length=500),
        existing_nullable=False,
    )


def downgrade() -> None:
    """Downgrade schema."""
    op.alter_column(
        "alvos",
        "contato",
        existing_type=sa.String(length=500),
        type_=sa.String(length=200),
        existing_nullable=False,
    )
