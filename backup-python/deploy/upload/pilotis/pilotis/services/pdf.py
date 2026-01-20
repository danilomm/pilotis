"""
Geração de PDF para declaração de filiação.
"""
import io
from pathlib import Path

from reportlab.lib.pagesizes import A4
from reportlab.lib.units import cm
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_JUSTIFY
from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph
from reportlab.lib.colors import HexColor

from ..config import settings


# Caminho do logo (usar JPG para evitar problemas com transparência)
LOGO_PATH = Path(__file__).parent.parent / "static" / "logo-docomomo.jpg"

# Cor verde do Docomomo
VERDE_DOCOMOMO = HexColor("#4a8c4a")

# Mapeamento de categorias para nomes de exibição
NOMES_CATEGORIAS = {
    "profissional_internacional": "Filiação Plena Docomomo Internacional+Brasil",
    "profissional_nacional": "Filiação Plena Docomomo Brasil",
    "estudante": "Filiação Estudante Docomomo Brasil",
}


def formatar_valor(centavos: int) -> str:
    """Formata valor de centavos para reais."""
    return f"R${centavos / 100:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def gerar_declaracao(
    nome: str,
    email: str,
    categoria: str,
    ano: int,
    valor_centavos: int,
    coordenadora: str = "Marta Peixoto",
    gestao: str = "2026-2027",
) -> bytes:
    """
    Gera PDF da declaração de filiação.

    Retorna bytes do PDF.
    """
    buffer = io.BytesIO()
    c = canvas.Canvas(buffer, pagesize=A4)
    width, height = A4

    # Margens
    margin_left = 2.5 * cm
    margin_right = width - 2.5 * cm

    # Logo no topo
    if LOGO_PATH.exists():
        logo_width = 8 * cm
        logo_height = 2 * cm  # proporcional
        logo_x = (width - logo_width) / 2
        logo_y = height - 3 * cm
        c.drawImage(str(LOGO_PATH), logo_x, logo_y, width=logo_width, height=logo_height, preserveAspectRatio=True)

    # Título
    c.setFont("Helvetica-Bold", 16)
    c.drawCentredString(width / 2, height - 7 * cm, "DECLARAÇÃO")

    # Texto da declaração
    categoria_nome = NOMES_CATEGORIAS.get(categoria, categoria)
    valor_formatado = formatar_valor(valor_centavos)

    texto = (
        f"Declaramos para os devidos fins que {nome} é filiada/o ao "
        f"Docomomo Brasil na modalidade {categoria_nome} [Anuidade: {valor_formatado}] "
        f"para o período de janeiro a dezembro de {ano}."
    )

    # Texto justificado usando Paragraph
    estilo = ParagraphStyle(
        'Justified',
        fontName='Helvetica',
        fontSize=12,
        leading=18,
        alignment=TA_JUSTIFY,
    )

    # Largura disponível para o texto
    texto_width = width - 2 * margin_left

    # Cria parágrafo e calcula altura
    paragrafo = Paragraph(texto, estilo)
    para_width, para_height = paragrafo.wrap(texto_width, 10 * cm)

    # Desenha o parágrafo
    paragrafo.drawOn(c, margin_left, height - 10 * cm - para_height + 0.5 * cm)

    # Assinatura (posição fixa)
    assinatura_y = height - 16 * cm

    c.setFont("Helvetica-Bold", 12)
    c.drawString(margin_left, assinatura_y, coordenadora)

    c.setFont("Helvetica", 11)
    c.drawString(margin_left, assinatura_y - 0.6 * cm, "Coordenadora do Docomomo Brasil")
    c.drawString(margin_left, assinatura_y - 1.2 * cm, "Associação de Colaboradores do Docomomo Brasil")
    c.drawString(margin_left, assinatura_y - 1.8 * cm, f"Gestão {gestao}")
    c.drawString(margin_left, assinatura_y - 2.4 * cm, "@docomomobrasil")

    # Dados do filiado no rodapé
    rodape_y = height - 22 * cm

    c.setFont("Helvetica", 10)
    c.drawString(margin_left, rodape_y, nome)
    c.drawString(margin_left, rodape_y - 0.5 * cm, email)

    # Finaliza
    c.showPage()
    c.save()

    buffer.seek(0)
    return buffer.read()


def salvar_declaracao(
    caminho: Path,
    nome: str,
    email: str,
    categoria: str,
    ano: int,
    valor_centavos: int,
) -> Path:
    """
    Gera e salva PDF da declaração.

    Retorna caminho do arquivo.
    """
    pdf_bytes = gerar_declaracao(nome, email, categoria, ano, valor_centavos)
    caminho.write_bytes(pdf_bytes)
    return caminho
