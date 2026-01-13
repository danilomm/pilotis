# Requisitos de Servidor — Pilotis

Sistema de gestão de filiados do Docomomo Brasil.

## Opção 1: Python (preferencial)

### Software necessário
- **Python 3.10+** (testado com 3.11 e 3.12)
- **pip** (gerenciador de pacotes Python)
- **SQLite 3** (geralmente já instalado)

### Recursos
- **RAM:** 512 MB mínimo (1 GB recomendado)
- **Disco:** 100 MB para aplicação + espaço para banco de dados
- **CPU:** 1 core é suficiente

### Acesso necessário
- Porta HTTP (80 ou 443 com SSL)
- Acesso a APIs externas (PagBank, Brevo)
- Possibilidade de rodar processo em background (uvicorn)

### Instalação
```bash
pip install -r requirements.txt
uvicorn pilotis.main:app --host 0.0.0.0 --port 8000
```

## Funcionalidades que dependem do servidor

| Funcionalidade | Requisito |
|----------------|-----------|
| Webhook PagBank | URL pública acessível (HTTPS recomendado) |
| Envio de lembretes | Cron job ou agendador de tarefas |
| Backup automático | Cron job (opcional) |

---

## Perguntas para o provedor

1. Vocês têm **Python 3.10+** instalado?
2. Se não, têm **Node.js 18+**?
3. É possível rodar um **processo em background** (daemon/serviço)?
4. Posso receber **webhooks** (requisições POST de serviços externos)?
5. Têm **SSL/HTTPS** disponível?

