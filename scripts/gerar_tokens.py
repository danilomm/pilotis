#!/usr/bin/env python3
"""
Gera tokens únicos para cadastrados que ainda não possuem.

Uso:
    python scripts/gerar_tokens.py
    python scripts/gerar_tokens.py --dry-run  # apenas simula
"""

import argparse
import secrets
import sys
from pathlib import Path

# Adiciona o diretório raiz ao path para importar o módulo pilotis
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from pilotis.db import get_connection, init_db


def gerar_token() -> str:
    """Gera um token URL-safe de 16 bytes (22 caracteres)."""
    return secrets.token_urlsafe(16)


def gerar_tokens(dry_run: bool = False) -> dict:
    """
    Gera tokens para cadastrados que não possuem.

    Retorna estatísticas.
    """
    stats = {"total": 0, "sem_token": 0, "gerados": 0}

    with get_connection() as conn:
        # Conta total
        total = conn.execute("SELECT COUNT(*) FROM cadastrados").fetchone()[0]
        stats["total"] = total

        # Busca cadastrados sem token
        sem_token = conn.execute(
            "SELECT id, nome, email FROM cadastrados WHERE token IS NULL"
        ).fetchall()
        stats["sem_token"] = len(sem_token)

        print(f"Total de cadastrados: {total}")
        print(f"Sem token: {len(sem_token)}")

        if dry_run:
            print("\n[DRY-RUN] Tokens que seriam gerados:")
            for row in sem_token[:5]:
                token = gerar_token()
                print(f"  - {row['nome']} <{row['email']}> -> {token}")
            if len(sem_token) > 5:
                print(f"  ... e mais {len(sem_token) - 5} cadastrados")
            return stats

        # Gera tokens
        for row in sem_token:
            token = gerar_token()
            conn.execute(
                "UPDATE cadastrados SET token = ? WHERE id = ?",
                (token, row["id"]),
            )
            stats["gerados"] += 1

        conn.commit()

    return stats


def main():
    parser = argparse.ArgumentParser(description="Gera tokens para cadastrados")
    parser.add_argument("--dry-run", action="store_true", help="Simula sem alterar")
    args = parser.parse_args()

    # Garante que o banco existe
    init_db()

    stats = gerar_tokens(args.dry_run)

    print("\nResumo:")
    print(f"  Total de cadastrados: {stats['total']}")
    print(f"  Sem token antes: {stats['sem_token']}")
    print(f"  Tokens gerados: {stats['gerados']}")


if __name__ == "__main__":
    main()
