# Importacao de Dados de Filiacao

Esta pasta contem os arquivos de importacao de filiados do Docomomo Brasil.

## Estrutura

```
importacao/
├── originais/          # Planilhas originais (Google Forms, consolidados)
├── limpos/             # CSVs limpos e normalizados (prontos para importar)
├── scripts/            # Scripts PHP de limpeza e importacao
└── README.md           # Este arquivo
```

## Arquivos Originais

### 2022
- `Ficha de Inscrição Docomomo Brasil (respostas) - 2022.csv`
  - Fonte: Google Forms "Ficha de Inscricao Docomomo Brasil"
  - Exportado em: Janeiro 2025
  - 190 registros

### 2023
- `Ficha de Inscrição Docomomo Brasil (respostas) - 2023.csv`
  - Fonte: Google Forms "Ficha de Inscricao Docomomo Brasil"
  - Exportado em: Janeiro 2025
  - 148 registros

### 2024
- `Docomomo Brasil Filiação 2024 (respostas) - Respostas ao formulário 1.csv`
  - Fonte: Google Forms "Docomomo Brasil Filiacao 2024"
  - Exportado em: Janeiro 2025
  - 178 registros

### 2025
- `cadastrados_docomomo_2025_consolidado.csv`
  - Fonte: Consolidado de multiplas fontes (PagBank, inscritos seminario, etc.)
  - Criado em: Janeiro 2025
  - ~682 registros

## Arquivos Limpos

CSVs processados e normalizados, prontos para importar no banco:

- `filiados_2022_limpo.csv` - 186 registros (apos remocao de duplicatas)
- `filiados_2023_limpo.csv` - 142 registros (apos remocao de duplicatas)

### Formato dos CSVs Limpos

Separador: `;` (ponto e virgula)
Encoding: UTF-8 com BOM

Colunas:
- `nome` - Nome completo
- `email` - Email principal (lowercase)
- `categoria` - profissional_internacional | profissional_nacional | estudante
- `valor` - Valor pago em centavos
- `data_pagamento` - YYYY-MM-DD
- `metodo` - PIX | Cartao | Boleto | Deposito | Manual | etc.
- `cpf` - XXX.XXX.XXX-XX ou vazio
- `telefone` - Formato livre
- `endereco` - Endereco completo (sem CEP)
- `cep` - XXXXX-XXX ou vazio
- `cidade` - Nome da cidade
- `estado` - Sigla UF (SP, RJ, etc.)
- `pais` - Nome do pais (Brasil, etc.)
- `profissao` - Livre
- `formacao` - Valores normalizados (ver lista)
- `instituicao` - Valores normalizados (ver mapeamento)
- `seminario` - S | N | vazio

## Scripts

### Limpeza (CSV original -> CSV limpo)

- `limpar_csv_2022.php` - Processa 2022, normaliza campos
- `limpar_csv_2023.php` - Processa 2023, normaliza campos

### Importacao (CSV limpo -> banco SQLite)

- `importar_csv_2022.php` - Importa no banco (com merge de duplicatas)
- `importar_csv_2023.php` - Importa no banco

### Normalizacao

- `instituicoes_normalizadas.php` - Mapa de normalizacao de instituicoes (~400 entradas)
- `cidades_normalizadas.php` - Mapa de normalizacao de cidades (~100 entradas)
- `normalizar_2024_2025.php` - Aplica normalizacao de instituicoes em 2024/2025
- `normalizar_cidades.php` - Aplica normalizacao de cidades em todos os anos
- `atualizar_normalizacao.php` - Atualiza banco com CSVs limpos

### Correcoes manuais

- `enderecos_2022_manual.php` - Correcoes de endereco para 2022

## Normalizacao de Campos

### Formacoes

Valores aceitos (definidos em `src/config.php`):
- Ensino Medio
- Graduacao em andamento
- Graduacao
- Especializacao / MBA em andamento
- Especializacao / MBA
- Mestrado em andamento
- Mestrado
- Doutorado em andamento
- Doutorado
- Pos-Doutorado

### Instituicoes

Mapeamento em `scripts/instituicoes_normalizadas.php`.

Exemplos de normalizacao:
- "faculdade de arquitetura e urbanismo da usp" -> "FAU-USP"
- "iau usp" -> "IAU-USP"
- "universidade presbiteriana mackenzie" -> "Mackenzie"
- "propar ufrgs" -> "PROPAR-UFRGS"

### Cidades

Mapeamento em `scripts/cidades_normalizadas.php`.

Regras de normalizacao:
- Remover estado/UF do nome (ex: "Aracaju - SE" -> "Aracaju")
- Acentuar corretamente (ex: "Sao Paulo" -> "São Paulo")
- Capitalizar corretamente (ex: "FORTALEZA" -> "Fortaleza")
- Preposicoes em minusculo (ex: "Rio De Janeiro" -> "Rio de Janeiro")
- Estado como cidade vira vazio (ex: "Santa Catarina" -> "")

Exemplos:
- "São Paulo Sp" -> "São Paulo"
- "Belém/Pará" -> "Belém"
- "Florianopolis" -> "Florianópolis"
- "CAMPINAS" -> "Campinas"

### Metodos de Pagamento

- PIX
- Cartao
- Boleto
- Deposito
- Manual
- Desconhecido

## Roteiro de Importacao

### Para importar um novo ano

1. Exportar planilha Google Forms como CSV
2. Salvar em `importacao/originais/`
3. Criar script `limpar_csv_XXXX.php` baseado nos existentes
4. Gerar CSV limpo em `importacao/limpos/`
5. Criar script `importar_csv_XXXX.php`
6. Executar importacao
7. Verificar duplicatas com `scripts/admin.php buscar "nome"`

### Para atualizar normalizacao

**Instituicoes:**
1. Adicionar novos mapeamentos em `instituicoes_normalizadas.php`
2. Rodar `normalizar_2024_2025.php` ou equivalente
3. Verificar resultados

**Cidades:**
1. Adicionar novos mapeamentos em `cidades_normalizadas.php`
2. Rodar `php scripts/normalizar_cidades.php`
3. Verificar resultados

## Historico

- 2025-01-22: Normalizacao de cidades em todos os anos (38 registros)
- 2025-01-22: Criacao desta estrutura organizada
- 2025-01-22: Normalizacao de 2024/2025 (metodos e instituicoes)
- 2025-01-22: Reimportacao de 2022/2023 com preservacao de unidades (FAU-USP, IAU-USP)
- 2025-01-21: Importacao inicial de 2022
- 2025-01-21: Importacao inicial de 2023
