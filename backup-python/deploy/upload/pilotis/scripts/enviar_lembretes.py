#!/usr/bin/env python3
"""
Script para enviar lembretes de pagamento pendente.

Deve ser executado diariamente via cron.

Uso:
    python scripts/enviar_lembretes.py
    python scripts/enviar_lembretes.py --dry-run

Lógica:
    - Envia lembrete no dia do vencimento (3º dia)
    - Depois, envia lembrete semanal até fim da campanha ou pagamento
"""
import argparse
import asyncio
import sys
from datetime import datetime, timedelta
from pathlib import Path

# Adiciona o diretório raiz ao path
sys.path.insert(0, str(Path(__file__).parent.parent))

from pilotis.db import fetchall, fetchone, registrar_log
from pilotis.services import email


async def enviar_lembretes(dry_run: bool):
    """Envia lembretes de pagamento pendente."""
    hoje = datetime.now().date()

    # Busca pagamentos pendentes
    pagamentos_pendentes = fetchall(
        """
        SELECT p.*, c.nome, c.email, c.token
        FROM pagamentos p
        JOIN cadastrados c ON p.cadastrado_id = c.id
        WHERE p.status = 'pendente'
        AND p.data_vencimento IS NOT NULL
        """,
        ()
    )

    print(f"=== Lembretes de Pagamento ===")
    print(f"Data: {hoje}")
    print(f"Pagamentos pendentes: {len(pagamentos_pendentes)}")
    if dry_run:
        print("MODO DRY-RUN (nenhum email será enviado)")
    print()

    enviados = 0
    ignorados = 0

    for pag in pagamentos_pendentes:
        # Calcula dias até vencimento
        if pag["data_vencimento"]:
            # data_vencimento pode estar em formato ISO string
            if isinstance(pag["data_vencimento"], str):
                vencimento = datetime.fromisoformat(pag["data_vencimento"].replace("Z", "+00:00"))
                vencimento = vencimento.date() if hasattr(vencimento, 'date') else datetime.strptime(pag["data_vencimento"][:10], "%Y-%m-%d").date()
            else:
                vencimento = pag["data_vencimento"].date() if hasattr(pag["data_vencimento"], 'date') else pag["data_vencimento"]

            dias_restantes = (vencimento - hoje).days
        else:
            dias_restantes = 3  # default

        # Verifica se deve enviar lembrete
        # - No dia do vencimento (dias_restantes <= 0)
        # - Ou a cada 7 dias após o vencimento
        deve_enviar = False

        if dias_restantes <= 0:
            # Dia do vencimento ou vencido
            # Verifica se já enviou hoje
            ultimo_lembrete = fetchone(
                """
                SELECT * FROM log
                WHERE tipo = 'lembrete_enviado'
                AND cadastrado_id = ?
                AND DATE(timestamp) = DATE('now')
                """,
                (pag["cadastrado_id"],)
            )
            if not ultimo_lembrete:
                deve_enviar = True
        elif dias_restantes <= 3:
            # Próximo do vencimento (3 dias ou menos)
            # Verifica se já enviou nos últimos 2 dias
            ultimo_lembrete = fetchone(
                """
                SELECT * FROM log
                WHERE tipo = 'lembrete_enviado'
                AND cadastrado_id = ?
                AND timestamp > datetime('now', '-2 days')
                """,
                (pag["cadastrado_id"],)
            )
            if not ultimo_lembrete:
                deve_enviar = True

        if not deve_enviar:
            ignorados += 1
            continue

        print(f"  {pag['nome']} <{pag['email']}> - {dias_restantes} dias restantes", end="")

        if dry_run:
            print(" [DRY-RUN]")
            enviados += 1
        else:
            try:
                valor_centavos = int(pag["valor"] * 100)
                ok = await email.enviar_lembrete_pagamento(
                    email=pag["email"],
                    nome=pag["nome"],
                    ano=pag["ano"],
                    token=pag["token"],
                    dias_restantes=dias_restantes,
                    valor_centavos=valor_centavos,
                )
                if ok:
                    print(" [OK]")
                    registrar_log("lembrete_enviado", pag["cadastrado_id"], f"Lembrete enviado: {dias_restantes} dias")
                    enviados += 1
                else:
                    print(" [ERRO]")
            except Exception as e:
                print(f" [ERRO: {e}]")

    print(f"\nEnviados: {enviados}")
    print(f"Ignorados (já enviado recentemente): {ignorados}")


def main():
    parser = argparse.ArgumentParser(description="Envia lembretes de pagamento")
    parser.add_argument("--dry-run", action="store_true", help="Simula envio sem enviar")

    args = parser.parse_args()

    asyncio.run(enviar_lembretes(args.dry_run))

    print("\nConcluído!")


if __name__ == "__main__":
    main()
