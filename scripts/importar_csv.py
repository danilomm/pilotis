#!/usr/bin/env python3
"""
Importa cadastrados de um arquivo CSV para o banco de dados.

Uso:
    python scripts/importar_csv.py desenvolvimento/cadastrados_docomomo_2025_consolidado.csv
    python scripts/importar_csv.py arquivo.csv --dry-run  # apenas simula
"""

import argparse
import csv
import sys
from pathlib import Path

# Adiciona o diretório raiz ao path para importar o módulo pilotis
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from pilotis.db import get_connection, init_db


def normalizar(valor: str) -> str | None:
    """Normaliza um valor: strip e converte vazio para None."""
    if valor is None:
        return None
    valor = valor.strip()
    return valor if valor else None


def importar_csv(caminho: str, dry_run: bool = False) -> dict:
    """
    Importa cadastrados de um arquivo CSV.

    Retorna estatísticas da importação.
    """
    caminho = Path(caminho)
    if not caminho.exists():
        print(f"Erro: arquivo não encontrado: {caminho}")
        sys.exit(1)

    stats = {"total": 0, "inseridos": 0, "duplicados": 0, "erros": 0}

    with open(caminho, "r", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        registros = list(reader)

    stats["total"] = len(registros)
    print(f"Lendo {stats['total']} registros de {caminho}")

    if dry_run:
        print("\n[DRY-RUN] Simulando importação...\n")
        for r in registros[:5]:
            print(f"  - {r.get('nome', 'SEM NOME')} <{r.get('email', 'SEM EMAIL')}> [{r.get('categoria', 'SEM CATEGORIA')}]")
        if len(registros) > 5:
            print(f"  ... e mais {len(registros) - 5} registros")
        return stats

    with get_connection() as conn:
        for registro in registros:
            nome = normalizar(registro.get("nome"))
            email = normalizar(registro.get("email"))

            if not nome or not email:
                print(f"  Pulando registro sem nome ou email: {registro}")
                stats["erros"] += 1
                continue

            # Verifica duplicata por email
            existe = conn.execute(
                "SELECT id FROM cadastrados WHERE email = ?", (email,)
            ).fetchone()

            if existe:
                stats["duplicados"] += 1
                continue

            # Normaliza estado (máximo 2 caracteres)
            estado = normalizar(registro.get("estado"))
            if estado and len(estado) > 2:
                estado = estado[:2].upper()

            try:
                conn.execute(
                    """
                    INSERT INTO cadastrados (
                        nome, email, cpf, telefone, endereco, cep,
                        cidade, estado, pais, profissao, formacao,
                        instituicao, categoria, observacoes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    """,
                    (
                        nome,
                        email,
                        normalizar(registro.get("cpf")),
                        normalizar(registro.get("telefone")),
                        normalizar(registro.get("endereco")),
                        normalizar(registro.get("cep")),
                        normalizar(registro.get("cidade")),
                        estado,
                        normalizar(registro.get("pais")) or "Brasil",
                        normalizar(registro.get("profissao")),
                        normalizar(registro.get("formacao")),
                        normalizar(registro.get("instituicao")),
                        normalizar(registro.get("categoria")),
                        f"fonte: {registro.get('fonte', '')}; seminario_2025: {registro.get('seminario_2025', '')}",
                    ),
                )
                stats["inseridos"] += 1
            except Exception as e:
                print(f"  Erro ao inserir {nome}: {e}")
                stats["erros"] += 1

        conn.commit()

    return stats


def main():
    parser = argparse.ArgumentParser(description="Importa cadastrados de um CSV")
    parser.add_argument("arquivo", help="Caminho do arquivo CSV")
    parser.add_argument("--dry-run", action="store_true", help="Simula sem inserir")
    args = parser.parse_args()

    # Garante que o banco existe
    init_db()

    stats = importar_csv(args.arquivo, args.dry_run)

    print("\nResumo:")
    print(f"  Total no arquivo: {stats['total']}")
    print(f"  Inseridos: {stats['inseridos']}")
    print(f"  Duplicados (ignorados): {stats['duplicados']}")
    print(f"  Erros: {stats['erros']}")


if __name__ == "__main__":
    main()
