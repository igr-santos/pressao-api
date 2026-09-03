from pressao_api.utils.validadores import (
    obter_mensagem_erro_compatibilidade,
    validar_compatibilidade_canal_alvo,
    validar_email,
    validar_telefone,
)


class TestFormatoEmailTelefone:
    def test_validar_email_correto(self):
        assert validar_email("usuario@email.com") is True
        assert validar_email("nome.sobrenome@dominio.com.br") is True
        assert validar_email("user+tag@email.com") is True

    def test_validar_email_incorreto(self):
        assert validar_email("usuario@") is False
        assert validar_email("usuario@email") is False
        assert validar_email("usuario.email.com") is False
        assert validar_email("") is False

    def test_validar_telefone_correto(self):
        assert validar_telefone("(11) 99999-9999") is True
        assert validar_telefone("11999999999") is True
        assert validar_telefone("+55 11 99999-9999") is True

    def test_validar_telefone_incorreto(self):
        assert validar_telefone("12345") is False
        assert validar_telefone("abc") is False
        assert validar_telefone("") is False


class TestCompatibilidadeCanalAlvo:
    def test_compatibilidade_email_com_email(self):
        """Email é compatível com alvo do tipo email"""
        assert validar_compatibilidade_canal_alvo("email", "email") is True

    def test_compatibilidade_email_com_telefone(self):
        """Email NÃO é compatível com alvo do tipo telefone"""
        assert validar_compatibilidade_canal_alvo("email", "telefone") is False

    def test_compatibilidade_telefone_com_telefone(self):
        """Telefone é compatível com alvo do tipo telefone"""
        assert validar_compatibilidade_canal_alvo("telefone", "telefone") is True

    def test_compatibilidade_telefone_com_email(self):
        """Telefone NÃO é compatível com alvo do tipo email"""
        assert validar_compatibilidade_canal_alvo("telefone", "email") is False

    def test_compatibilidade_whatsapp_com_whatsapp(self):
        """WhatsApp é compatível com alvo do tipo whatsapp"""
        assert validar_compatibilidade_canal_alvo("whatsapp", "whatsapp") is True

    def test_compatibilidade_whatsapp_com_telefone(self):
        """WhatsApp NÃO é compatível com alvo do tipo telefone"""
        assert validar_compatibilidade_canal_alvo("whatsapp", "telefone") is False

    def test_compatibilidade_instagram_com_instagram(self):
        """Instagram é compatível com alvo do tipo instagram"""
        assert validar_compatibilidade_canal_alvo("instagram", "instagram") is True

    def test_compatibilidade_instagram_com_email(self):
        """Instagram NÃO é compatível com alvo do tipo email"""
        assert validar_compatibilidade_canal_alvo("instagram", "email") is False

    def test_compatibilidade_tiktok_com_tiktok(self):
        """TikTok é compatível com alvo do tipo tiktok"""
        assert validar_compatibilidade_canal_alvo("tiktok", "tiktok") is True

    def test_compatibilidade_tiktok_com_instagram(self):
        """TikTok NÃO é compatível com alvo do tipo instagram"""
        assert validar_compatibilidade_canal_alvo("tiktok", "instagram") is False

    def test_mensagem_erro_compatibilidade(self):
        """Testa mensagem de erro"""
        mensagem = obter_mensagem_erro_compatibilidade("email", "telefone")
        assert "email" in mensagem
        assert "telefone" in mensagem
        assert "não é compatível" in mensagem
