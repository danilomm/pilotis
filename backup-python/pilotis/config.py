import os
from pathlib import Path
from dotenv import load_dotenv

# Carrega .env do diretório raiz do projeto
BASE_DIR = Path(__file__).resolve().parent.parent
load_dotenv(BASE_DIR / ".env")


class Settings:
    # Banco
    DATABASE_PATH: str = os.getenv("DATABASE_PATH", "data/pilotis.db")

    # PagBank
    PAGBANK_TOKEN: str = os.getenv("PAGBANK_TOKEN", "")
    PAGBANK_SANDBOX: bool = os.getenv("PAGBANK_SANDBOX", "true").lower() == "true"

    @property
    def PAGBANK_API_URL(self) -> str:
        if self.PAGBANK_SANDBOX:
            return "https://sandbox.api.pagseguro.com"
        return "https://api.pagseguro.com"

    # Email (Brevo)
    BREVO_API_KEY: str = os.getenv("BREVO_API_KEY", "")
    EMAIL_FROM: str = os.getenv("EMAIL_FROM", "Docomomo Brasil <tesouraria@docomomobrasil.com>")

    # App
    BASE_URL: str = os.getenv("BASE_URL", "http://localhost:8000")
    SECRET_KEY: str = os.getenv("SECRET_KEY", "chave-padrao-desenvolvimento")
    ADMIN_PASSWORD: str = os.getenv("ADMIN_PASSWORD", "")

    # Valores de filiacao (centavos)
    VALOR_ESTUDANTE: int = int(os.getenv("VALOR_ESTUDANTE", "11500"))
    VALOR_PROFISSIONAL: int = int(os.getenv("VALOR_PROFISSIONAL", "23000"))
    VALOR_INTERNACIONAL: int = int(os.getenv("VALOR_INTERNACIONAL", "46000"))

    def valor_por_categoria(self, categoria: str) -> int:
        """Retorna o valor em centavos para uma categoria."""
        valores = {
            "estudante": self.VALOR_ESTUDANTE,
            "profissional": self.VALOR_PROFISSIONAL,
            "profissional_internacional": self.VALOR_INTERNACIONAL,
        }
        return valores.get(categoria, self.VALOR_PROFISSIONAL)


settings = Settings()
