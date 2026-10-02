from uuid import uuid4

import pytest

from pressao_api.core.security import get_current_user
from pressao_api.main import app


class TestAPIAlvo:
    @pytest.fixture
    def campanha(self, client, db_session, mock_admin):
        """Cria uma campanha para os testes"""
        app.dependency_overrides[get_current_user] = lambda: mock_admin

        response = client.post("/api/v1/campanhas/", json={"nome": "Campanha Teste Alvos"})
        return response.json()

    def test_criar_alvo(self, client, db_session, mock_user, campanha):
        """Cria alvo para campanha"""
        app.dependency_overrides[get_current_user] = lambda: mock_user
        campanha_id = campanha["id"]

        response = client.post(
            "/api/v1/alvos/",
            json={
                "nome": "João Silva",
                "contato": "joao@email.com",
                "tipo_contato": "email",
                "campanha_id": campanha_id,
                "metadados": {"cargo": "Analista"},
            },
        )
        assert response.status_code == 201
        data = response.json()
        assert data["nome"] == "João Silva"
        assert data["contato"] == "joao@email.com"
        assert data["tipo_contato"] == "email"
        assert data["campanha_id"] == campanha_id

    def test_criar_alvo_com_contato_duplicado_na_campanha(
        self, client, db_session, mock_user, campanha
    ):
        """Não permite criar alvo com mesmo contato na mesma campanha"""
        app.dependency_overrides[get_current_user] = lambda: mock_user
        campanha_id = campanha["id"]

        # Cria o primeiro alvo
        client.post(
            "/api/v1/alvos/",
            json={
                "nome": "João Silva",
                "contato": "joao@email.com",
                "tipo_contato": "email",
                "campanha_id": campanha_id,
            },
        )

        # Tenta criar outro com mesmo contato
        response = client.post(
            "/api/v1/alvos/",
            json={
                "nome": "João Silva 2",
                "contato": "joao@email.com",
                "tipo_contato": "email",
                "campanha_id": campanha_id,
            },
        )
        assert response.status_code == 400
        assert "contato já está cadastrado" in response.text

    def test_criar_alvo_para_campanha_inexistente(self, client, db_session, mock_user):
        """Não permite criar alvo para campanha inexistente"""
        app.dependency_overrides[get_current_user] = lambda: mock_user

        response = client.post(
            "/api/v1/alvos/",
            json={
                "nome": "João Silva",
                "contato": "joao@email.com",
                "tipo_contato": "email",
                "campanha_id": str(uuid4()),
            },
        )
        assert response.status_code == 404
        assert "Campanha não encontrada" in response.text

    def test_listar_alvos_por_campanha(self, client, db_session, mock_user, campanha):
        """Lista alvos de uma campanha"""
        app.dependency_overrides[get_current_user] = lambda: mock_user
        campanha_id = campanha["id"]

        # Cria alguns alvos
        for i in range(3):
            client.post(
                "/api/v1/alvos/",
                json={
                    "nome": f"Alvo {i}",
                    "contato": f"alvo{i}@email.com",
                    "tipo_contato": "email",
                    "campanha_id": campanha_id,
                },
            )

        response = client.get(f"/api/v1/alvos/campanha/{campanha_id}")
        assert response.status_code == 200
        data = response.json()
        assert len(data) == 1
        assert data[0]["modo"] == "agregado"
        assert data[0]["total_membros"] == 3
        assert data[0]["membros"] == [{"nome": "Alvo 0"}, {"nome": "Alvo 1"}, {"nome": "Alvo 2"}]

    def test_buscar_alvo_por_id(self, client, db_session, mock_user, campanha):
        """Busca alvo por ID"""
        app.dependency_overrides[get_current_user] = lambda: mock_user
        campanha_id = campanha["id"]

        # Cria alvo
        create_response = client.post(
            "/api/v1/alvos/",
            json={
                "nome": "Busca Alvo",
                "contato": "busca@email.com",
                "tipo_contato": "email",
                "campanha_id": campanha_id,
            },
        )
        alvo_id = create_response.json()["id"]

        # Busca alvo
        response = client.get(f"/api/v1/alvos/{alvo_id}")
        assert response.status_code == 200
        data = response.json()
        assert data["nome"] == "Busca Alvo"
        assert data["id"] == alvo_id

    def test_atualizar_alvo(self, client, db_session, mock_user, campanha):
        """Atualiza alvo"""
        app.dependency_overrides[get_current_user] = lambda: mock_user
        campanha_id = campanha["id"]

        # Cria alvo
        create_response = client.post(
            "/api/v1/alvos/",
            json={
                "nome": "Nome Antigo",
                "contato": "antigo@email.com",
                "tipo_contato": "email",
                "campanha_id": campanha_id,
            },
        )
        alvo_id = create_response.json()["id"]

        # Atualiza
        response = client.put(
            f"/api/v1/alvos/{alvo_id}", json={"nome": "Nome Novo", "ativo": False}
        )
        assert response.status_code == 200
        data = response.json()
        assert data["nome"] == "Nome Novo"
        assert data["ativo"] is False

    def test_deletar_alvo(self, client, db_session, mock_user, campanha):
        """Deleta alvo"""
        app.dependency_overrides[get_current_user] = lambda: mock_user
        campanha_id = campanha["id"]

        # Cria alvo
        create_response = client.post(
            "/api/v1/alvos/",
            json={
                "nome": "Alvo Deletar",
                "contato": "deletar@email.com",
                "tipo_contato": "email",
                "campanha_id": campanha_id,
            },
        )
        alvo_id = create_response.json()["id"]

        # Deleta
        response = client.delete(f"/api/v1/alvos/{alvo_id}")
        assert response.status_code == 204

        # Verifica que não existe mais
        get_response = client.get(f"/api/v1/alvos/{alvo_id}")
        assert get_response.status_code == 404

    def test_criar_alvo_com_email_invalido(self, client, db_session, mock_user, campanha):
        """Não permite criar alvo com email inválido"""
        app.dependency_overrides[get_current_user] = lambda: mock_user
        campanha_id = campanha["id"]

        response = client.post(
            "/api/v1/alvos/",
            json={
                "nome": "João Silva",
                "contato": "email-invalido",
                "tipo_contato": "email",
                "campanha_id": campanha_id,
            },
        )
        assert response.status_code == 422
        assert "Formato de e-mail inválido" in response.text

    def test_criar_alvo_com_telefone_invalido(self, client, db_session, mock_user, campanha):
        """Não permite criar alvo com telefone inválido"""
        app.dependency_overrides[get_current_user] = lambda: mock_user
        campanha_id = campanha["id"]

        response = client.post(
            "/api/v1/alvos/",
            json={
                "nome": "Maria Silva",
                "contato": "123",
                "tipo_contato": "telefone",
                "campanha_id": campanha_id,
            },
        )
        assert response.status_code == 422
        assert "Formato de telefone inválido" in response.text
