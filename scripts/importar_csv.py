#!/usr/bin/env python3
"""
Importa cadastrados de um arquivo CSV para o banco de dados.
Detecta e unifica duplicatas por email ou nome similar.

Uso:
    python scripts/importar_csv.py desenvolvimento/cadastrados_docomomo_2025_consolidado.csv
    python scripts/importar_csv.py arquivo.csv --dry-run  # apenas simula
"""

import argparse
import csv
import sys
from collections import defaultdict
from difflib import SequenceMatcher
from pathlib import Path

# Adiciona o diretório raiz ao path para importar o módulo pilotis
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from pilotis.db import get_connection, init_db

# Limiar de similaridade para considerar nomes como duplicados
SIMILARIDADE_MINIMA = 0.85

# Nomes que não devem ser unificados mesmo com alta similaridade
EXCECOES = [
    ("adriana monzillo de oliveira", "luciana monzillo de oliveira"),  # pessoas diferentes
]


def normalizar(valor: str) -> str | None:
    """Normaliza um valor: strip e converte vazio para None."""
    if valor is None:
        return None
    valor = valor.strip()
    return valor if valor else None


def normalizar_emails(email_str: str) -> list[str]:
    """Extrai lista de emails normalizados de uma string."""
    if not email_str:
        return []
    return [e.strip().lower() for e in email_str.split(";") if e.strip()]


def similaridade(a: str, b: str) -> float:
    """Calcula similaridade entre duas strings (0-1)."""
    return SequenceMatcher(None, a.lower(), b.lower()).ratio()


def eh_excecao(nome1: str, nome2: str) -> bool:
    """Verifica se par de nomes está na lista de exceções."""
    n1, n2 = nome1.lower(), nome2.lower()
    for e1, e2 in EXCECOES:
        if (n1 == e1 and n2 == e2) or (n1 == e2 and n2 == e1):
            return True
    return False


def escolher_melhor_valor(v1: str | None, v2: str | None) -> str | None:
    """Escolhe o valor mais completo entre dois."""
    if not v1:
        return v2
    if not v2:
        return v1
    # Prefere o mais longo (geralmente mais completo)
    return v1 if len(v1) >= len(v2) else v2


def unificar_registros(r1: dict, r2: dict) -> dict:
    """Unifica dois registros, mantendo os dados mais completos."""
    unificado = {}

    # Campos simples: escolhe o mais completo
    campos_simples = [
        "cpf", "telefone", "endereco", "cep", "cidade",
        "estado", "pais", "profissao", "formacao", "instituicao"
    ]

    for campo in campos_simples:
        unificado[campo] = escolher_melhor_valor(
            normalizar(r1.get(campo)),
            normalizar(r2.get(campo))
        )

    # Nome: escolhe o mais completo
    unificado["nome"] = escolher_melhor_valor(r1.get("nome"), r2.get("nome"))

    # Email: agrega todos
    emails1 = normalizar_emails(r1.get("email", ""))
    emails2 = normalizar_emails(r2.get("email", ""))
    todos_emails = list(dict.fromkeys(emails1 + emails2))  # remove duplicados mantendo ordem
    unificado["email"] = "; ".join(todos_emails)

    # Categoria: prioridade (filiado > participante)
    prioridade = {
        "profissional_internacional": 1,
        "profissional": 2,
        "estudante": 3,
        "participante_seminario": 4,
    }
    cat1 = r1.get("categoria", "participante_seminario")
    cat2 = r2.get("categoria", "participante_seminario")
    unificado["categoria"] = cat1 if prioridade.get(cat1, 5) <= prioridade.get(cat2, 5) else cat2

    # Observações: combina fontes
    fontes = set()
    for r in [r1, r2]:
        if r.get("fonte"):
            fontes.add(r["fonte"])
    seminario = "sim" if any(r.get("seminario_2025") == "sim" for r in [r1, r2]) else "não"
    unificado["observacoes"] = f"fontes: {', '.join(sorted(fontes))}; seminario_2025: {seminario}; UNIFICADO"

    return unificado


def processar_duplicatas(registros: list[dict]) -> list[dict]:
    """
    Processa lista de registros, unificando duplicatas.
    Retorna lista de registros únicos.
    """
    # Índice por email individual
    email_para_idx = {}
    # Índice por nome normalizado
    nome_para_idx = {}
    # Lista de registros processados
    processados = []
    # Conjunto de índices já unificados
    unificados = set()

    for i, registro in enumerate(registros):
        if i in unificados:
            continue

        emails = normalizar_emails(registro.get("email", ""))
        nome = registro.get("nome", "").strip()

        # Procura duplicata por email
        duplicata_idx = None
        for email in emails:
            if email in email_para_idx:
                duplicata_idx = email_para_idx[email]
                break

        # Se não achou por email, procura por nome similar
        if duplicata_idx is None:
            for nome_existente, idx in nome_para_idx.items():
                if similaridade(nome, nome_existente) >= SIMILARIDADE_MINIMA:
                    if not eh_excecao(nome, nome_existente):
                        duplicata_idx = idx
                        break

        if duplicata_idx is not None:
            # Unifica com registro existente
            registro_existente = processados[duplicata_idx]
            registro_unificado = unificar_registros(registro_existente, registro)
            processados[duplicata_idx] = registro_unificado

            # Atualiza índices
            for email in normalizar_emails(registro_unificado["email"]):
                email_para_idx[email] = duplicata_idx

            unificados.add(i)
            print(f"  Unificado: '{registro.get('nome')}' com '{registro_existente.get('nome')}'")
        else:
            # Novo registro
            idx = len(processados)
            processados.append(registro)

            for email in emails:
                email_para_idx[email] = idx
            if nome:
                nome_para_idx[nome] = idx

    return processados


def importar_csv(caminho: str, dry_run: bool = False) -> dict:
    """
    Importa cadastrados de um arquivo CSV.
    Retorna estatísticas da importação.
    """
    caminho = Path(caminho)
    if not caminho.exists():
        print(f"Erro: arquivo não encontrado: {caminho}")
        sys.exit(1)

    stats = {"total_csv": 0, "apos_unificacao": 0, "inseridos": 0, "erros": 0}

    with open(caminho, "r", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        registros = list(reader)

    stats["total_csv"] = len(registros)
    print(f"Lendo {stats['total_csv']} registros de {caminho}")

    print("\nProcessando duplicatas...")
    registros = processar_duplicatas(registros)
    stats["apos_unificacao"] = len(registros)
    print(f"Após unificação: {stats['apos_unificacao']} registros únicos")

    if dry_run:
        print("\n[DRY-RUN] Simulando importação...\n")
        for r in registros[:10]:
            print(f"  - {r.get('nome', 'SEM NOME')} <{r.get('email', 'SEM EMAIL')}> [{r.get('categoria', 'SEM CATEGORIA')}]")
        if len(registros) > 10:
            print(f"  ... e mais {len(registros) - 10} registros")
        return stats

    with get_connection() as conn:
        for registro in registros:
            nome = normalizar(registro.get("nome"))
            email = normalizar(registro.get("email"))

            if not nome or not email:
                print(f"  Pulando registro sem nome ou email: {registro}")
                stats["erros"] += 1
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
                        registro.get("observacoes") or f"fonte: {registro.get('fonte', '')}; seminario_2025: {registro.get('seminario_2025', '')}",
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
    print(f"  Total no CSV: {stats['total_csv']}")
    print(f"  Após unificação: {stats['apos_unificacao']}")
    print(f"  Inseridos: {stats['inseridos']}")
    print(f"  Erros: {stats['erros']}")


if __name__ == "__main__":
    main()
