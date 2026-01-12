#!/usr/bin/env python3
"""
Script para enviar campanha de filiação.

Uso:
    python scripts/enviar_campanha.py --ano 2026 --dry-run
    python scripts/enviar_campanha.py --ano 2026

Argumentos:
    --ano         Ano da campanha (obrigatório)
    --dry-run     Simula envio sem enviar de fato
    --tipo        Tipo de campanha: 'renovacao', 'convite' ou 'todos' (default: todos)
    --limite      Limite de emails por execução (default: sem limite)
"""
import argparse
import asyncio
import sys
from pathlib import Path

# Adiciona o diretório raiz ao path
sys.path.insert(0, str(Path(__file__).parent.parent))

from pilotis.db import fetchall, registrar_log
from pilotis.services import email


async def enviar_campanha(ano: int, dry_run: bool, tipo: str, limite: int | None):
    """Envia emails da campanha de filiação."""

    # Busca cadastrados para campanha de renovação (filiados do ano anterior)
    if tipo in ("renovacao", "todos"):
        filiados_anterior = fetchall(
            """
            SELECT DISTINCT c.* FROM cadastrados c
            JOIN pagamentos p ON c.id = p.cadastrado_id
            WHERE p.ano = ? AND p.status = 'pago'
            AND c.id NOT IN (
                SELECT cadastrado_id FROM pagamentos WHERE ano = ? AND status = 'pago'
            )
            """,
            (ano - 1, ano)
        )
        print(f"\n=== Campanha de Renovação ===")
        print(f"Filiados {ano-1} que ainda não pagaram {ano}: {len(filiados_anterior)}")

        for i, cadastrado in enumerate(filiados_anterior):
            if limite and i >= limite:
                print(f"Limite de {limite} atingido.")
                break

            print(f"  [{i+1}] {cadastrado['nome']} <{cadastrado['email']}>", end="")

            if dry_run:
                print(" [DRY-RUN]")
            else:
                try:
                    ok = await email.enviar_campanha_renovacao(
                        email=cadastrado["email"],
                        nome=cadastrado["nome"],
                        ano=ano,
                        token=cadastrado["token"],
                    )
                    if ok:
                        print(" [OK]")
                        registrar_log("campanha_renovacao", cadastrado["id"], f"Email de renovação enviado para {ano}")
                    else:
                        print(" [ERRO]")
                except Exception as e:
                    print(f" [ERRO: {e}]")

    # Busca cadastrados para campanha de convite (nunca foram filiados)
    if tipo in ("convite", "todos"):
        nunca_filiados = fetchall(
            """
            SELECT c.* FROM cadastrados c
            WHERE c.id NOT IN (
                SELECT DISTINCT cadastrado_id FROM pagamentos WHERE status = 'pago'
            )
            AND c.categoria IN ('participante_seminario', 'cadastrado')
            """,
            ()
        )
        print(f"\n=== Campanha de Convite ===")
        print(f"Cadastrados que nunca foram filiados: {len(nunca_filiados)}")

        for i, cadastrado in enumerate(nunca_filiados):
            if limite and i >= limite:
                print(f"Limite de {limite} atingido.")
                break

            print(f"  [{i+1}] {cadastrado['nome']} <{cadastrado['email']}>", end="")

            if dry_run:
                print(" [DRY-RUN]")
            else:
                try:
                    ok = await email.enviar_campanha_convite(
                        email=cadastrado["email"],
                        nome=cadastrado["nome"],
                        ano=ano,
                        token=cadastrado["token"],
                    )
                    if ok:
                        print(" [OK]")
                        registrar_log("campanha_convite", cadastrado["id"], f"Email de convite enviado para {ano}")
                    else:
                        print(" [ERRO]")
                except Exception as e:
                    print(f" [ERRO: {e}]")


def main():
    parser = argparse.ArgumentParser(description="Envia campanha de filiação")
    parser.add_argument("--ano", type=int, required=True, help="Ano da campanha")
    parser.add_argument("--dry-run", action="store_true", help="Simula envio sem enviar")
    parser.add_argument("--tipo", choices=["renovacao", "convite", "todos"], default="todos", help="Tipo de campanha")
    parser.add_argument("--limite", type=int, help="Limite de emails por execução")

    args = parser.parse_args()

    print(f"Campanha de filiação {args.ano}")
    print(f"Tipo: {args.tipo}")
    if args.dry_run:
        print("MODO DRY-RUN (nenhum email será enviado)")

    asyncio.run(enviar_campanha(args.ano, args.dry_run, args.tipo, args.limite))

    print("\nConcluído!")


if __name__ == "__main__":
    main()
