from dataclasses import dataclass, field
from datetime import datetime
from typing import Literal

CategoriaType = Literal["estudante", "profissional", "profissional_internacional", "participante_seminario"]
StatusPagamentoType = Literal["pendente", "pago", "cancelado", "expirado"]
MetodoPagamentoType = Literal["pix", "boleto", "cartao"]


@dataclass
class Cadastrado:
    id: int | None = None
    nome: str = ""
    email: str = ""
    cpf: str | None = None
    telefone: str | None = None
    endereco: str | None = None
    cep: str | None = None
    cidade: str | None = None
    estado: str | None = None
    pais: str = "Brasil"
    profissao: str | None = None
    formacao: str | None = None
    instituicao: str | None = None
    categoria: CategoriaType | None = None
    seminario_2025: bool = False
    data_cadastro: datetime | None = None
    data_atualizacao: datetime | None = None
    token: str | None = None
    token_expira: datetime | None = None
    observacoes: str | None = None

    @classmethod
    def from_row(cls, row) -> "Cadastrado":
        """Cria um Cadastrado a partir de uma linha do banco."""
        if row is None:
            return None
        return cls(
            id=row["id"],
            nome=row["nome"],
            email=row["email"],
            cpf=row["cpf"],
            telefone=row["telefone"],
            endereco=row["endereco"],
            cep=row["cep"],
            cidade=row["cidade"],
            estado=row["estado"],
            pais=row["pais"],
            profissao=row["profissao"],
            formacao=row["formacao"],
            instituicao=row["instituicao"],
            categoria=row["categoria"],
            seminario_2025=bool(row["seminario_2025"]),
            data_cadastro=row["data_cadastro"],
            data_atualizacao=row["data_atualizacao"],
            token=row["token"],
            token_expira=row["token_expira"],
            observacoes=row["observacoes"],
        )

    def emails_lista(self) -> list[str]:
        """Retorna lista de emails (pode haver múltiplos separados por '; ')."""
        if not self.email:
            return []
        return [e.strip() for e in self.email.split(";") if e.strip()]


@dataclass
class Pagamento:
    id: int | None = None
    cadastrado_id: int = 0
    ano: int = 0
    valor: float = 0.0
    status: StatusPagamentoType = "pendente"
    metodo: MetodoPagamentoType | None = None
    pagbank_order_id: str | None = None
    pagbank_charge_id: str | None = None
    data_criacao: datetime | None = None
    data_pagamento: datetime | None = None
    data_vencimento: datetime | None = None

    @classmethod
    def from_row(cls, row) -> "Pagamento":
        """Cria um Pagamento a partir de uma linha do banco."""
        if row is None:
            return None
        return cls(
            id=row["id"],
            cadastrado_id=row["cadastrado_id"],
            ano=row["ano"],
            valor=row["valor"],
            status=row["status"],
            metodo=row["metodo"],
            pagbank_order_id=row["pagbank_order_id"],
            pagbank_charge_id=row["pagbank_charge_id"],
            data_criacao=row["data_criacao"],
            data_pagamento=row["data_pagamento"],
            data_vencimento=row["data_vencimento"],
        )
