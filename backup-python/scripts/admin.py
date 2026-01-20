#!/usr/bin/env python3
"""
Ferramenta de administracao do Pilotis.

Uso:
    python scripts/admin.py pendentes              # Lista pagamentos pendentes
    python scripts/admin.py buscar "email@exemplo" # Busca cadastrado
    python scripts/admin.py pagar ID               # Marca pagamento como pago
    python scripts/admin.py novo                   # Cadastra pessoa + pagamento manual
    python scripts/admin.py exportar               # Exporta filiados para CSV
"""
import sys
import csv
from pathlib import Path
from datetime import datetime

sys.path.insert(0, str(Path(__file__).parent.parent))

from pilotis.db import fetchall, fetchone, execute, get_connection


def listar_pendentes(ano: int = None):
    """Lista pagamentos pendentes."""
    if ano is None:
        ano = datetime.now().year

    rows = fetchall("""
        SELECT p.id, c.nome, c.email, p.valor, p.metodo, p.data_criacao, p.data_vencimento
        FROM pagamentos p
        JOIN cadastrados c ON c.id = p.cadastrado_id
        WHERE p.ano = ? AND p.status = 'pendente'
        ORDER BY p.data_criacao DESC
    """, (ano,))

    if not rows:
        print(f"Nenhum pagamento pendente para {ano}.")
        return

    print(f"\nPagamentos pendentes ({ano}):\n")
    print(f"{'ID':>4} | {'Nome':<30} | {'Valor':>10} | {'Metodo':<7} | {'Criado':<10}")
    print("-" * 75)
    for r in rows:
        data = r['data_criacao'][:10] if r['data_criacao'] else '-'
        metodo = r['metodo'] or '-'
        print(f"{r['id']:>4} | {r['nome'][:30]:<30} | R$ {r['valor']/100:>7.2f} | {metodo:<7} | {data}")

    print(f"\nTotal: {len(rows)} pendentes")


def buscar_cadastrado(termo: str):
    """Busca cadastrado por email ou nome."""
    rows = fetchall("""
        SELECT c.id, c.nome, c.email, c.categoria, c.token,
               p.id as pag_id, p.ano, p.status, p.valor
        FROM cadastrados c
        LEFT JOIN pagamentos p ON p.cadastrado_id = c.id
        WHERE c.email LIKE ? OR c.nome LIKE ?
        ORDER BY c.nome, p.ano DESC
    """, (f"%{termo}%", f"%{termo}%"))

    if not rows:
        print(f"Nenhum resultado para '{termo}'")
        return

    print(f"\nResultados para '{termo}':\n")

    cadastrado_atual = None
    for r in rows:
        if r['id'] != cadastrado_atual:
            cadastrado_atual = r['id']
            print(f"\n[{r['id']}] {r['nome']}")
            print(f"    Email: {r['email']}")
            print(f"    Categoria: {r['categoria'] or '-'}")
            print(f"    Token: {r['token'] or '-'}")
            print(f"    Pagamentos:")

        if r['pag_id']:
            status_icon = "✓" if r['status'] == 'pago' else "○"
            print(f"      {status_icon} [{r['pag_id']}] {r['ano']}: R$ {r['valor']/100:.2f} ({r['status']})")


def marcar_pago(pagamento_id: int):
    """Marca um pagamento como pago manualmente."""
    pag = fetchone("""
        SELECT p.*, c.nome, c.email
        FROM pagamentos p
        JOIN cadastrados c ON c.id = p.cadastrado_id
        WHERE p.id = ?
    """, (pagamento_id,))

    if not pag:
        print(f"Pagamento {pagamento_id} nao encontrado.")
        return

    if pag['status'] == 'pago':
        print(f"Pagamento {pagamento_id} ja esta marcado como pago.")
        return

    print(f"\nPagamento #{pagamento_id}")
    print(f"  Nome: {pag['nome']}")
    print(f"  Email: {pag['email']}")
    print(f"  Ano: {pag['ano']}")
    print(f"  Valor: R$ {pag['valor']/100:.2f}")
    print(f"  Status atual: {pag['status']}")

    confirm = input("\nConfirma marcar como PAGO? (s/N): ")
    if confirm.lower() != 's':
        print("Cancelado.")
        return

    execute("""
        UPDATE pagamentos
        SET status = 'pago',
            metodo = COALESCE(metodo, 'manual'),
            data_pagamento = CURRENT_TIMESTAMP
        WHERE id = ?
    """, (pagamento_id,))

    # Log
    execute("""
        INSERT INTO log (tipo, cadastrado_id, mensagem)
        VALUES ('pagamento_manual', ?, ?)
    """, (pag['cadastrado_id'], f"Pagamento {pagamento_id} marcado como pago manualmente"))

    print(f"\n✓ Pagamento {pagamento_id} marcado como PAGO!")
    print(f"  Lembre-se de enviar o email de confirmacao manualmente se necessario.")


def cadastrar_novo():
    """Cadastra nova pessoa e cria pagamento."""
    print("\n=== Novo cadastro + pagamento manual ===\n")

    nome = input("Nome completo: ").strip()
    if not nome:
        print("Nome obrigatorio.")
        return

    email = input("Email: ").strip().lower()
    if not email:
        print("Email obrigatorio.")
        return

    # Verifica se ja existe
    existente = fetchone("SELECT id, nome FROM cadastrados WHERE email = ?", (email,))
    if existente:
        print(f"\nEmail ja cadastrado: [{existente['id']}] {existente['nome']}")
        usar = input("Usar este cadastro? (S/n): ")
        if usar.lower() == 'n':
            print("Cancelado.")
            return
        cadastrado_id = existente['id']
    else:
        cpf = input("CPF (opcional): ").strip()
        telefone = input("Telefone (opcional): ").strip()

        print("\nCategorias:")
        print("  1. Estudante (R$ 115)")
        print("  2. Profissional Brasil (R$ 230)")
        print("  3. Profissional Internacional (R$ 460)")
        cat_opcao = input("Categoria [1-3]: ").strip()

        categorias = {
            '1': ('estudante', 11500),
            '2': ('profissional_nacional', 23000),
            '3': ('profissional_internacional', 46000),
        }

        if cat_opcao not in categorias:
            print("Categoria invalida.")
            return

        categoria, valor = categorias[cat_opcao]

        with get_connection() as conn:
            cursor = conn.execute("""
                INSERT INTO cadastrados (nome, email, cpf, telefone, categoria)
                VALUES (?, ?, ?, ?, ?)
            """, (nome, email, cpf or None, telefone or None, categoria))
            cadastrado_id = cursor.lastrowid
            conn.commit()

        print(f"\n✓ Cadastrado criado: ID {cadastrado_id}")

    # Criar pagamento
    ano = input(f"Ano do pagamento [{datetime.now().year}]: ").strip()
    ano = int(ano) if ano else datetime.now().year

    # Verifica se ja tem pagamento para o ano
    pag_existe = fetchone("""
        SELECT id, status FROM pagamentos
        WHERE cadastrado_id = ? AND ano = ?
    """, (cadastrado_id, ano))

    if pag_existe:
        if pag_existe['status'] == 'pago':
            print(f"Ja existe pagamento PAGO para {ano}.")
            return
        else:
            marcar = input(f"Ja existe pagamento pendente [{pag_existe['id']}]. Marcar como pago? (S/n): ")
            if marcar.lower() != 'n':
                marcar_pago(pag_existe['id'])
            return

    # Pega categoria e valor
    cad = fetchone("SELECT categoria FROM cadastrados WHERE id = ?", (cadastrado_id,))
    valores = {
        'estudante': 11500,
        'profissional_nacional': 23000,
        'profissional_internacional': 46000,
    }
    valor = valores.get(cad['categoria'], 23000)

    with get_connection() as conn:
        cursor = conn.execute("""
            INSERT INTO pagamentos (cadastrado_id, ano, valor, status, metodo, data_pagamento)
            VALUES (?, ?, ?, 'pago', 'manual', CURRENT_TIMESTAMP)
        """, (cadastrado_id, ano, valor))
        pag_id = cursor.lastrowid
        conn.commit()

    print(f"\n✓ Pagamento criado e marcado como PAGO!")
    print(f"  ID: {pag_id}")
    print(f"  Valor: R$ {valor/100:.2f}")
    print(f"  Ano: {ano}")


def exportar_filiados(ano: int = None):
    """Exporta filiados pagos para CSV."""
    if ano is None:
        ano = datetime.now().year

    rows = fetchall("""
        SELECT c.nome, c.email, c.cpf, c.telefone, c.categoria,
               c.endereco, c.cep, c.cidade, c.estado, c.pais,
               c.profissao, c.instituicao,
               p.valor, p.metodo, p.data_pagamento
        FROM cadastrados c
        JOIN pagamentos p ON p.cadastrado_id = c.id
        WHERE p.ano = ? AND p.status = 'pago'
        ORDER BY c.nome
    """, (ano,))

    if not rows:
        print(f"Nenhum filiado pago em {ano}.")
        return

    filename = f"filiados_{ano}.csv"
    with open(filename, 'w', newline='', encoding='utf-8') as f:
        writer = csv.writer(f)
        writer.writerow([
            'Nome', 'Email', 'CPF', 'Telefone', 'Categoria',
            'Endereco', 'CEP', 'Cidade', 'Estado', 'Pais',
            'Profissao', 'Instituicao',
            'Valor', 'Metodo', 'Data Pagamento'
        ])
        for r in rows:
            writer.writerow([
                r['nome'], r['email'], r['cpf'], r['telefone'], r['categoria'],
                r['endereco'], r['cep'], r['cidade'], r['estado'], r['pais'],
                r['profissao'], r['instituicao'],
                f"R$ {r['valor']/100:.2f}", r['metodo'], r['data_pagamento']
            ])

    print(f"\n✓ Exportado: {filename}")
    print(f"  Total: {len(rows)} filiados")


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return

    comando = sys.argv[1].lower()

    if comando == 'pendentes':
        ano = int(sys.argv[2]) if len(sys.argv) > 2 else None
        listar_pendentes(ano)

    elif comando == 'buscar':
        if len(sys.argv) < 3:
            print("Uso: python admin.py buscar <email ou nome>")
            return
        buscar_cadastrado(sys.argv[2])

    elif comando == 'pagar':
        if len(sys.argv) < 3:
            print("Uso: python admin.py pagar <ID do pagamento>")
            return
        marcar_pago(int(sys.argv[2]))

    elif comando == 'novo':
        cadastrar_novo()

    elif comando == 'exportar':
        ano = int(sys.argv[2]) if len(sys.argv) > 2 else None
        exportar_filiados(ano)

    else:
        print(f"Comando desconhecido: {comando}")
        print(__doc__)


if __name__ == "__main__":
    main()
