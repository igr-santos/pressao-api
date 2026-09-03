import re


def validar_email(email: str) -> bool:
    """
    Valida formato de e-mail usando regex.
    Suporta: nome@dominio.com, nome.sobrenome@dominio.com.br
    """
    padrao = r"^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$"
    return bool(re.match(padrao, email))


def validar_telefone(telefone: str) -> bool:
    """
    Valida formato de telefone.
    Suporta: (11) 99999-9999, 11999999999, +55 11 99999-9999
    """
    # Remove caracteres não numéricos
    numeros = re.sub(r"\D", "", telefone)

    # Verifica se tem entre 10 e 15 dígitos (incluindo DDI)
    return 10 <= len(numeros) <= 15


COMPATIBILIDADE_CANAL_CONTATO = {
    "email": ["email"],
    "telefone": ["telefone"],
    "whatsapp": ["whatsapp"],
    "instagram": ["instagram"],
    "tiktok": ["tiktok"],
}


def validar_compatibilidade_canal_alvo(canal: str, tipo_contato: str) -> bool:
    """
    Valida se o canal da ação é compatível com o tipo de contato do alvo.

    Args:
        canal: Canal da ação (email, telefone, whatsapp, instagram, tiktok)
        tipo_contato: Tipo de contato do alvo (email, telefone, whatsapp, instagram, tiktok)

    Returns:
        True se compatível, False caso contrário
    """
    canais_permitidos = COMPATIBILIDADE_CANAL_CONTATO.get(tipo_contato, [])
    return canal in canais_permitidos


def obter_mensagem_erro_compatibilidade(canal: str, tipo_contato: str) -> str:
    """Retorna mensagem de erro amigável para incompatibilidade"""
    return f"O canal '{canal}' não é compatível com o tipo de contato '{tipo_contato}' do alvo"
