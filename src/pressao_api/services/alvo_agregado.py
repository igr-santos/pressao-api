from uuid import UUID

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from pressao_api.models.alvo import Alvo, ModoAlvo, TipoContato
from pressao_api.repositories.alvo_membro_repository import AlvoMembroRepository
from pressao_api.repositories.alvo_repository import AlvoRepository

NOME_AGREGADO_EMAIL = "Pressionar por E-mail"


def _contato_agregado(campanha_id: UUID) -> str:
    return f"agregado.{campanha_id}@pressao.local"


class AlvoAgregadoService:
    def __init__(self, session: AsyncSession):
        self.session = session
        self.alvo_repo = AlvoRepository(session)
        self.membro_repo = AlvoMembroRepository(session)

    async def buscar_agregado_email(self, campanha_id: UUID) -> Alvo | None:
        query = select(Alvo).where(
            Alvo.campanha_id == campanha_id,
            Alvo.tipo_contato == TipoContato.EMAIL,
            Alvo.modo == ModoAlvo.AGREGADO,
        )
        result = await self.session.execute(query)
        return result.scalar_one_or_none()

    async def garantir_agregado_email(self, campanha_id: UUID) -> Alvo:
        agregado = await self.buscar_agregado_email(campanha_id)
        if agregado:
            return agregado

        return await self.alvo_repo.criar(
            {
                "campanha_id": campanha_id,
                "nome": NOME_AGREGADO_EMAIL,
                "contato": _contato_agregado(campanha_id),
                "tipo_contato": TipoContato.EMAIL,
                "modo": ModoAlvo.AGREGADO,
                "metadados": {"agregado_email": True},
                "ativo": True,
            }
        )

    async def _listar_email_individual(
        self, campanha_id: UUID, ativo: bool | None = None
    ) -> list[Alvo]:
        alvos = await self.alvo_repo.listar_por_campanha(campanha_id, ativo)
        return [
            alvo
            for alvo in alvos
            if alvo.tipo_contato == TipoContato.EMAIL and alvo.modo == ModoAlvo.INDIVIDUAL
        ]

    async def sincronizar_membros(self, campanha_id: UUID) -> int:
        agregado = await self.garantir_agregado_email(campanha_id)
        membros = await self._listar_email_individual(campanha_id, ativo=True)
        return await self.membro_repo.sincronizar(agregado.id, [m.id for m in membros])

    async def listar_para_exibicao(
        self, campanha_id: UUID, ativo: bool | None = None
    ) -> list[Alvo]:
        await self.sincronizar_membros(campanha_id)
        agregado = await self.buscar_agregado_email(campanha_id)
        membros_count = await self.membro_repo.contar_membros(agregado.id) if agregado else 0

        todos = await self.alvo_repo.listar_por_campanha(campanha_id, ativo)
        individuais: list[Alvo] = []
        for alvo in todos:
            if alvo.modo == ModoAlvo.AGREGADO:
                continue
            if alvo.tipo_contato == TipoContato.EMAIL:
                continue
            individuais.append(alvo)

        resultado: list[Alvo] = []
        if agregado and membros_count > 0 and (ativo is None or agregado.ativo == ativo):
            resultado.append(agregado)
        resultado.extend(individuais)
        return resultado

    async def contar_membros_agregado(self, agregado_id: UUID) -> int:
        return await self.membro_repo.contar_membros(agregado_id)

    async def listar_nomes_membros(self, agregado_id: UUID) -> list[str]:
        """Nomes dos membros ativos do agregado, em ordem alfabética (sem contatos)."""
        membros = await self.membro_repo.listar_membros_alvos(agregado_id)
        return sorted((m.nome for m in membros), key=str.casefold)
