import pytest

from pressao_api.core.security import get_current_user
from pressao_api.main import app


class TestAPITiktok:
    @pytest.fixture
    def campanha(self, client, db_session, mock_admin):
        app.dependency_overrides[get_current_user] = lambda: mock_admin
        return client.post("/api/v1/campanhas/", json={"nome": "Campanha TikTok"}).json()

    def test_criar_alvo_tiktok(self, client, db_session, mock_admin, campanha):
        """Permite cadastrar alvo do tipo TikTok com URL de vídeo em contato."""
        app.dependency_overrides[get_current_user] = lambda: mock_admin

        response = client.post(
            "/api/v1/alvos/",
            json={
                "nome": "Perfil TikTok",
                "contato": "https://www.tiktok.com/@perfil_tiktok/video/111",
                "tipo_contato": "tiktok",
                "campanha_id": campanha["id"],
            },
        )

        assert response.status_code == 201
        assert response.json()["tipo_contato"] == "tiktok"
        assert response.json()["nome"] == "Perfil TikTok"
        assert "tiktok.com" in response.json()["contato"]

    def test_criar_e_filtrar_template_tiktok(self, client, db_session, mock_admin, campanha):
        """Templates podem ser cadastrados e filtrados pelo canal TikTok."""
        app.dependency_overrides[get_current_user] = lambda: mock_admin
        campanha_id = campanha["id"]

        client.post(
            "/api/v1/templates/",
            json={
                "campanha_id": campanha_id,
                "canal": "instagram",
                "titulo": "Instagram",
                "conteudo": "Mensagem Instagram",
                "ativo": True,
            },
        )
        criado = client.post(
            "/api/v1/templates/",
            json={
                "campanha_id": campanha_id,
                "canal": "tiktok",
                "titulo": "TikTok",
                "conteudo": "Mensagem TikTok",
                "ativo": True,
            },
        )

        assert criado.status_code == 201
        response = client.get(f"/api/v1/templates/campanha/{campanha_id}?canal=tiktok")

        assert response.status_code == 200
        data = response.json()
        assert len(data) == 1
        assert data[0]["canal"] == "tiktok"
        assert data[0]["titulo"] == "TikTok"

    def test_alvo_tiktok_recebe_template_sorteado(self, client, db_session, mock_admin, campanha):
        """A listagem e o detalhe do alvo TikTok expõem template sorteado."""
        app.dependency_overrides[get_current_user] = lambda: mock_admin
        campanha_id = campanha["id"]
        alvo = client.post(
            "/api/v1/alvos/",
            json={
                "nome": "Perfil TikTok",
                "contato": "@perfil_tiktok",
                "tipo_contato": "tiktok",
                "campanha_id": campanha_id,
            },
        ).json()
        template = client.post(
            "/api/v1/templates/",
            json={
                "campanha_id": campanha_id,
                "canal": "tiktok",
                "titulo": "Mensagem TikTok",
                "conteudo": "Copie esta mensagem para o TikTok",
                "ativo": True,
            },
        ).json()

        list_response = client.get(f"/api/v1/alvos/campanha/{campanha_id}")
        detail_response = client.get(f"/api/v1/alvos/{alvo['id']}")

        assert list_response.status_code == 200
        assert list_response.json()[0]["template"]["id"] == template["id"]
        assert detail_response.status_code == 200
        assert detail_response.json()["template"]["id"] == template["id"]

    def test_criar_e_confirmar_acao_tiktok_com_template(
        self, client, db_session, mock_user, mock_admin, campanha
    ):
        """TikTok segue o mesmo fluxo manual do Instagram."""
        app.dependency_overrides[get_current_user] = lambda: mock_admin
        campanha_id = campanha["id"]
        alvo = client.post(
            "/api/v1/alvos/",
            json={
                "nome": "Perfil TikTok",
                "contato": "https://www.tiktok.com/@perfil_tiktok/video/9876543210",
                "tipo_contato": "tiktok",
                "campanha_id": campanha_id,
            },
        ).json()
        template = client.post(
            "/api/v1/templates/",
            json={
                "campanha_id": campanha_id,
                "canal": "tiktok",
                "titulo": "Mensagem TikTok",
                "conteudo": "Mensagem para copiar",
                "ativo": True,
            },
        ).json()

        app.dependency_overrides[get_current_user] = lambda: mock_user
        response = client.post(
            "/api/v1/acoes/",
            json={
                "campanha_id": campanha_id,
                "alvo_id": alvo["id"],
                "canal": "tiktok",
                "template_id": template["id"],
                "anonimo": False,
                "ativista": {"nome": "Teste", "email": "teste@email.com"},
            },
        )

        assert response.status_code == 201
        data = response.json()
        assert data["status_atual"] == "AGUARDANDO_ACAO_HUMANA"
        assert data["proximo_passo"]["tipo"] == "EXIBIR_TEXTO_E_ABRIR_PERFIL"
        assert data["proximo_passo"]["dados"]["texto"] == "Mensagem para copiar"
        assert data["proximo_passo"]["dados"]["template_id"] == template["id"]
        assert data["proximo_passo"]["dados"]["perfil"] == "Perfil TikTok"
        assert (
            data["proximo_passo"]["dados"]["url_postagem"]
            == "https://www.tiktok.com/@perfil_tiktok/video/9876543210"
        )

        confirm_response = client.patch(f"/api/v1/acoes/{data['acao_id']}/confirmar")

        assert confirm_response.status_code == 200
        assert confirm_response.json()["acoes_confirmadas"] == 1
