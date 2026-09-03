from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession

from pressao_api.core.database import get_db
from pressao_api.core.security import get_current_user
from pressao_api.models.alvo import Alvo, ModoAlvo, TipoContato
from pressao_api.models.template import Template
from pressao_api.repositories.alvo_repository import AlvoRepository
from pressao_api.repositories.campanha_repository import CampanhaRepository
from pressao_api.repositories.template_repository import TemplateRepository
from pressao_api.schemas.acao import CanalEnum
from pressao_api.schemas.alvo import AlvoCreate, AlvoResponse, AlvoUpdate
from pressao_api.schemas.template import TemplateSorteadoResponse
from pressao_api.services.alvo_agregado import AlvoAgregadoService
from pressao_api.services.templates import sortear_template

router = APIRouter(prefix="/alvos", tags=["Alvos"])

CANAIS_COM_TEMPLATE = {
    TipoContato.EMAIL: CanalEnum.EMAIL.value,
    TipoContato.INSTAGRAM: CanalEnum.INSTAGRAM.value,
    TipoContato.TIKTOK: CanalEnum.TIKTOK.value,
}


def _montar_resposta_com_template(
    alvo: Alvo,
    templates_por_canal: dict[str, list[Template]],
    total_membros: int | None = None,
) -> AlvoResponse:
    """Monta a resposta do alvo sorteando template para canais que suportam preview."""
    resposta = AlvoResponse.model_validate(alvo)
    if total_membros is not None:
        resposta.total_membros = total_membros

    canal_template = CANAIS_COM_TEMPLATE.get(alvo.tipo_contato)
    if not canal_template:
        return resposta

    sorteado = sortear_template(templates_por_canal.get(canal_template, []))
    if sorteado:
        resposta.template = TemplateSorteadoResponse.model_validate(sorteado)

    return resposta


@router.post("/", response_model=AlvoResponse, status_code=status.HTTP_201_CREATED)
async def criar_alvo(
    request: AlvoCreate,
    current_user: dict = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Cria um novo alvo para uma campanha"""
    campanha_repo = CampanhaRepository(db)
    campanha = await campanha_repo.buscar_por_id(request.campanha_id)
    if not campanha:
        raise HTTPException(status_code=404, detail="Campanha não encontrada")

    alvo_repo = AlvoRepository(db)
    alvos_existentes = await alvo_repo.buscar_por_contato(request.contato)
    for alvo in alvos_existentes:
        if alvo.campanha_id == request.campanha_id:
            raise HTTPException(
                status_code=400, detail="Este contato já está cadastrado nesta campanha"
            )

    alvo = await alvo_repo.criar(request.model_dump())

    if request.tipo_contato.value == TipoContato.EMAIL.value:
        agregado_service = AlvoAgregadoService(db)
        await agregado_service.sincronizar_membros(request.campanha_id)

    return alvo


@router.get("/campanha/{campanha_id}", response_model=list[AlvoResponse])
async def listar_alvos_por_campanha(
    campanha_id: UUID,
    ativo: bool | None = None,
    current_user: dict = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """
    Lista alvos para exibição na campanha.

    E-mails individuais são agrupados em um alvo agregado; outros canais listam-se
    individualmente. Alvos de e-mail, Instagram e TikTok vêm com template sorteado
    neste request quando houver templates ativos no canal.
    """
    agregado_service = AlvoAgregadoService(db)
    alvos = await agregado_service.listar_para_exibicao(campanha_id, ativo)

    template_repo = TemplateRepository(db)
    templates_por_canal = {}
    for canal in set(CANAIS_COM_TEMPLATE.values()):
        templates_por_canal[canal] = await template_repo.listar_ativos_por_canal(campanha_id, canal)

    respostas: list[AlvoResponse] = []
    for alvo in alvos:
        total_membros = None
        if alvo.modo == ModoAlvo.AGREGADO:
            total_membros = await agregado_service.contar_membros_agregado(alvo.id)
        respostas.append(
            _montar_resposta_com_template(alvo, templates_por_canal, total_membros=total_membros)
        )
    return respostas


@router.get("/{alvo_id}", response_model=AlvoResponse)
async def obter_alvo(
    alvo_id: UUID,
    current_user: dict = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    """Obtém um alvo; canais com template vêm com um template sorteado neste request."""
    repo = AlvoRepository(db)
    alvo = await repo.buscar_por_id(alvo_id)
    if not alvo:
        raise HTTPException(status_code=404, detail="Alvo não encontrado")

    template_repo = TemplateRepository(db)
    templates_por_canal = {}
    canal_template = CANAIS_COM_TEMPLATE.get(alvo.tipo_contato)
    if canal_template:
        templates_por_canal[canal_template] = await template_repo.listar_ativos_por_canal(
            alvo.campanha_id, canal_template
        )

    total_membros = None
    if alvo.modo == ModoAlvo.AGREGADO:
        agregado_service = AlvoAgregadoService(db)
        total_membros = await agregado_service.contar_membros_agregado(alvo.id)

    return _montar_resposta_com_template(alvo, templates_por_canal, total_membros=total_membros)


@router.put("/{alvo_id}", response_model=AlvoResponse)
async def atualizar_alvo(
    alvo_id: UUID,
    request: AlvoUpdate,
    current_user: dict = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    repo = AlvoRepository(db)
    alvo = await repo.atualizar(alvo_id, request.model_dump(exclude_none=True))
    if not alvo:
        raise HTTPException(status_code=404, detail="Alvo não encontrado")

    if alvo.tipo_contato == TipoContato.EMAIL and alvo.modo == ModoAlvo.INDIVIDUAL:
        agregado_service = AlvoAgregadoService(db)
        await agregado_service.sincronizar_membros(alvo.campanha_id)

    return alvo


@router.delete("/{alvo_id}", status_code=status.HTTP_204_NO_CONTENT)
async def deletar_alvo(
    alvo_id: UUID,
    current_user: dict = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
):
    repo = AlvoRepository(db)
    alvo = await repo.buscar_por_id(alvo_id)
    if not alvo:
        raise HTTPException(status_code=404, detail="Alvo não encontrado")

    campanha_id = alvo.campanha_id
    era_email_individual = (
        alvo.tipo_contato == TipoContato.EMAIL and alvo.modo == ModoAlvo.INDIVIDUAL
    )

    deletado = await repo.deletar(alvo_id)
    if not deletado:
        raise HTTPException(status_code=404, detail="Alvo não encontrado")

    if era_email_individual:
        agregado_service = AlvoAgregadoService(db)
        await agregado_service.sincronizar_membros(campanha_id)
