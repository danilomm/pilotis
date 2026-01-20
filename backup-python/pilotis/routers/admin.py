"""
Rotas administrativas do Pilotis.
"""
import csv
import hashlib
import secrets
from io import StringIO
from datetime import datetime
from pathlib import Path

from fastapi import APIRouter, Request, Form, HTTPException
from fastapi.responses import HTMLResponse, RedirectResponse, FileResponse, StreamingResponse
from fastapi.templating import Jinja2Templates

from ..config import settings, BASE_DIR
from ..db import fetchall, fetchone, execute, get_connection, get_db_path

router = APIRouter(prefix="/admin", tags=["admin"])
templates = Jinja2Templates(directory=BASE_DIR / "pilotis" / "templates")

# Sessoes ativas (em memoria - reinicia com o servidor)
sessions: dict[str, datetime] = {}


def verificar_sessao(request: Request) -> bool:
    """Verifica se o usuario esta logado."""
    session_id = request.cookies.get("admin_session")
    if not session_id or session_id not in sessions:
        return False
    # Sessao valida por 24 horas
    if (datetime.now() - sessions[session_id]).total_seconds() > 86400:
        del sessions[session_id]
        return False
    return True


def exigir_login(request: Request):
    """Redireciona para login se nao autenticado."""
    if not verificar_sessao(request):
        raise HTTPException(status_code=303, headers={"Location": "/admin/login"})


@router.get("/login", response_class=HTMLResponse)
async def login_page(request: Request, erro: str = None):
    """Pagina de login."""
    return templates.TemplateResponse("admin/login.html", {
        "request": request,
        "erro": erro
    })


@router.post("/login")
async def login_submit(request: Request, senha: str = Form(...)):
    """Processa login."""
    if not settings.ADMIN_PASSWORD:
        return RedirectResponse("/admin/login?erro=Senha+admin+nao+configurada", status_code=303)

    # Compara senha (suporta tanto texto plano quanto hash)
    senha_correta = False
    if settings.ADMIN_PASSWORD.startswith("sha256:"):
        # Hash SHA256
        hash_esperado = settings.ADMIN_PASSWORD[7:]
        hash_fornecido = hashlib.sha256(senha.encode()).hexdigest()
        senha_correta = secrets.compare_digest(hash_esperado, hash_fornecido)
    else:
        # Texto plano (desenvolvimento)
        senha_correta = secrets.compare_digest(settings.ADMIN_PASSWORD, senha)

    if not senha_correta:
        return RedirectResponse("/admin/login?erro=Senha+incorreta", status_code=303)

    # Cria sessao
    session_id = secrets.token_urlsafe(32)
    sessions[session_id] = datetime.now()

    response = RedirectResponse("/admin", status_code=303)
    response.set_cookie("admin_session", session_id, httponly=True, max_age=86400)
    return response


@router.get("/logout")
async def logout(request: Request):
    """Encerra sessao."""
    session_id = request.cookies.get("admin_session")
    if session_id and session_id in sessions:
        del sessions[session_id]

    response = RedirectResponse("/admin/login", status_code=303)
    response.delete_cookie("admin_session")
    return response


@router.get("", response_class=HTMLResponse)
async def painel(request: Request, ano: int = None):
    """Painel principal."""
    if not verificar_sessao(request):
        return RedirectResponse("/admin/login", status_code=303)

    if ano is None:
        ano = datetime.now().year

    # Estatisticas
    stats = fetchone("""
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) as pagos,
            SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as pendentes,
            SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as arrecadado
        FROM pagamentos WHERE ano = ?
    """, (ano,))

    # Pagamentos recentes
    pagamentos = fetchall("""
        SELECT p.id, p.cadastrado_id, c.nome, c.email, p.valor, p.status, p.metodo,
               p.data_criacao, p.data_pagamento
        FROM pagamentos p
        JOIN cadastrados c ON c.id = p.cadastrado_id
        WHERE p.ano = ?
        ORDER BY
            CASE p.status WHEN 'pendente' THEN 0 ELSE 1 END,
            p.data_criacao DESC
        LIMIT 100
    """, (ano,))

    return templates.TemplateResponse("admin/painel.html", {
        "request": request,
        "ano": ano,
        "stats": stats,
        "pagamentos": pagamentos
    })


@router.get("/buscar", response_class=HTMLResponse)
async def buscar(request: Request, q: str = ""):
    """Busca cadastrados."""
    if not verificar_sessao(request):
        return RedirectResponse("/admin/login", status_code=303)

    resultados = []
    if q:
        resultados = fetchall("""
            SELECT c.id, c.nome, c.email, c.categoria, c.token,
                   GROUP_CONCAT(p.ano || ':' || p.status, ', ') as pagamentos
            FROM cadastrados c
            LEFT JOIN pagamentos p ON p.cadastrado_id = c.id
            WHERE c.email LIKE ? OR c.nome LIKE ?
            GROUP BY c.id
            ORDER BY c.nome
            LIMIT 50
        """, (f"%{q}%", f"%{q}%"))

    return templates.TemplateResponse("admin/buscar.html", {
        "request": request,
        "q": q,
        "resultados": resultados
    })


@router.get("/pessoa/{id}", response_class=HTMLResponse)
async def ver_pessoa(request: Request, id: int, salvo: bool = False):
    """Detalhes de uma pessoa."""
    if not verificar_sessao(request):
        return RedirectResponse("/admin/login", status_code=303)

    pessoa = fetchone("SELECT * FROM cadastrados WHERE id = ?", (id,))
    if not pessoa:
        raise HTTPException(status_code=404, detail="Pessoa nao encontrada")

    pagamentos = fetchall("""
        SELECT * FROM pagamentos
        WHERE cadastrado_id = ?
        ORDER BY ano DESC
    """, (id,))

    return templates.TemplateResponse("admin/pessoa.html", {
        "request": request,
        "pessoa": pessoa,
        "pagamentos": pagamentos,
        "salvo": salvo
    })


@router.post("/pessoa/{id}")
async def salvar_pessoa(
    request: Request,
    id: int,
    nome: str = Form(...),
    email: str = Form(...),
    cpf: str = Form(""),
    telefone: str = Form(""),
    categoria: str = Form(""),
    endereco: str = Form(""),
    cep: str = Form(""),
    cidade: str = Form(""),
    estado: str = Form(""),
    pais: str = Form("Brasil"),
    profissao: str = Form(""),
    formacao: str = Form(""),
    instituicao: str = Form(""),
    observacoes: str = Form(""),
    observacoes_filiado: str = Form("")
):
    """Salva alteracoes de uma pessoa."""
    if not verificar_sessao(request):
        return RedirectResponse("/admin/login", status_code=303)

    pessoa = fetchone("SELECT id FROM cadastrados WHERE id = ?", (id,))
    if not pessoa:
        raise HTTPException(status_code=404, detail="Pessoa nao encontrada")

    execute("""
        UPDATE cadastrados SET
            nome = ?, email = ?, cpf = ?, telefone = ?, categoria = ?,
            endereco = ?, cep = ?, cidade = ?, estado = ?, pais = ?,
            profissao = ?, formacao = ?, instituicao = ?,
            observacoes = ?, observacoes_filiado = ?,
            data_atualizacao = CURRENT_TIMESTAMP
        WHERE id = ?
    """, (
        nome.strip(), email.strip().lower(),
        cpf.strip() or None, telefone.strip() or None, categoria or None,
        endereco.strip() or None, cep.strip() or None,
        cidade.strip() or None, estado.strip().upper()[:2] or None, pais.strip() or None,
        profissao.strip() or None, formacao.strip() or None, instituicao.strip() or None,
        observacoes.strip() or None, observacoes_filiado.strip() or None,
        id
    ))

    execute("""
        INSERT INTO log (tipo, cadastrado_id, mensagem)
        VALUES ('edicao_admin', ?, 'Dados editados via admin')
    """, (id,))

    return RedirectResponse(f"/admin/pessoa/{id}?salvo=1", status_code=303)


@router.post("/pagar/{pagamento_id}")
async def marcar_pago(request: Request, pagamento_id: int):
    """Marca pagamento como pago."""
    if not verificar_sessao(request):
        return RedirectResponse("/admin/login", status_code=303)

    pag = fetchone("SELECT cadastrado_id, ano FROM pagamentos WHERE id = ?", (pagamento_id,))
    if not pag:
        raise HTTPException(status_code=404, detail="Pagamento nao encontrado")

    execute("""
        UPDATE pagamentos
        SET status = 'pago',
            metodo = COALESCE(metodo, 'manual'),
            data_pagamento = CURRENT_TIMESTAMP
        WHERE id = ?
    """, (pagamento_id,))

    execute("""
        INSERT INTO log (tipo, cadastrado_id, mensagem)
        VALUES ('pagamento_manual', ?, ?)
    """, (pag['cadastrado_id'], f"Pagamento {pagamento_id} marcado como pago via admin"))

    return RedirectResponse(f"/admin?ano={pag['ano']}", status_code=303)


@router.get("/novo", response_class=HTMLResponse)
async def novo_form(request: Request):
    """Formulario para novo cadastro."""
    if not verificar_sessao(request):
        return RedirectResponse("/admin/login", status_code=303)

    return templates.TemplateResponse("admin/novo.html", {
        "request": request,
        "ano": datetime.now().year
    })


@router.post("/novo")
async def novo_submit(
    request: Request,
    nome: str = Form(...),
    email: str = Form(...),
    categoria: str = Form(...),
    ano: int = Form(...),
    cpf: str = Form(""),
    telefone: str = Form("")
):
    """Cria novo cadastro + pagamento."""
    if not verificar_sessao(request):
        return RedirectResponse("/admin/login", status_code=303)

    email = email.strip().lower()

    # Verifica se ja existe
    existente = fetchone("SELECT id FROM cadastrados WHERE email = ?", (email,))
    if existente:
        cadastrado_id = existente['id']
        # Atualiza categoria se necessario
        execute("UPDATE cadastrados SET categoria = ? WHERE id = ?", (categoria, cadastrado_id))
    else:
        with get_connection() as conn:
            cursor = conn.execute("""
                INSERT INTO cadastrados (nome, email, cpf, telefone, categoria)
                VALUES (?, ?, ?, ?, ?)
            """, (nome.strip(), email, cpf.strip() or None, telefone.strip() or None, categoria))
            cadastrado_id = cursor.lastrowid
            conn.commit()

    # Verifica se ja tem pagamento para o ano
    pag_existe = fetchone("""
        SELECT id FROM pagamentos WHERE cadastrado_id = ? AND ano = ?
    """, (cadastrado_id, ano))

    if pag_existe:
        # Marca como pago
        execute("""
            UPDATE pagamentos
            SET status = 'pago', metodo = 'manual', data_pagamento = CURRENT_TIMESTAMP
            WHERE id = ?
        """, (pag_existe['id'],))
    else:
        # Cria pagamento
        valores = {
            'estudante': 11500,
            'profissional_nacional': 23000,
            'profissional_internacional': 46000,
        }
        valor = valores.get(categoria, 23000)

        execute("""
            INSERT INTO pagamentos (cadastrado_id, ano, valor, status, metodo, data_pagamento)
            VALUES (?, ?, ?, 'pago', 'manual', CURRENT_TIMESTAMP)
        """, (cadastrado_id, ano, valor))

    execute("""
        INSERT INTO log (tipo, cadastrado_id, mensagem)
        VALUES ('cadastro_manual', ?, 'Cadastro e pagamento criados via admin')
    """, (cadastrado_id,))

    return RedirectResponse(f"/admin/pessoa/{cadastrado_id}", status_code=303)


@router.post("/excluir/pagamento/{pagamento_id}")
async def excluir_pagamento(request: Request, pagamento_id: int):
    """Exclui um pagamento."""
    if not verificar_sessao(request):
        return RedirectResponse("/admin/login", status_code=303)

    pag = fetchone("SELECT cadastrado_id, ano FROM pagamentos WHERE id = ?", (pagamento_id,))
    if not pag:
        raise HTTPException(status_code=404, detail="Pagamento nao encontrado")

    execute("DELETE FROM pagamentos WHERE id = ?", (pagamento_id,))

    execute("""
        INSERT INTO log (tipo, cadastrado_id, mensagem)
        VALUES ('exclusao', ?, ?)
    """, (pag['cadastrado_id'], f"Pagamento {pagamento_id} excluido via admin"))

    return RedirectResponse(f"/admin/pessoa/{pag['cadastrado_id']}", status_code=303)


@router.post("/excluir/pessoa/{pessoa_id}")
async def excluir_pessoa(request: Request, pessoa_id: int):
    """Exclui uma pessoa e todos os seus pagamentos."""
    if not verificar_sessao(request):
        return RedirectResponse("/admin/login", status_code=303)

    pessoa = fetchone("SELECT nome FROM cadastrados WHERE id = ?", (pessoa_id,))
    if not pessoa:
        raise HTTPException(status_code=404, detail="Pessoa nao encontrada")

    execute("DELETE FROM pagamentos WHERE cadastrado_id = ?", (pessoa_id,))
    execute("DELETE FROM cadastrados WHERE id = ?", (pessoa_id,))

    execute("""
        INSERT INTO log (tipo, mensagem)
        VALUES ('exclusao', ?)
    """, (f"Pessoa {pessoa_id} ({pessoa['nome']}) excluida via admin",))

    return RedirectResponse("/admin", status_code=303)


@router.get("/download/banco")
async def download_banco(request: Request):
    """Download do arquivo do banco de dados."""
    if not verificar_sessao(request):
        return RedirectResponse("/admin/login", status_code=303)

    db_path = get_db_path()
    if not db_path.exists():
        raise HTTPException(status_code=404, detail="Banco nao encontrado")

    filename = f"pilotis_backup_{datetime.now().strftime('%Y%m%d_%H%M%S')}.db"
    return FileResponse(
        db_path,
        filename=filename,
        media_type="application/x-sqlite3"
    )


@router.get("/download/csv")
async def download_csv(request: Request, ano: int = None):
    """Download dos filiados em CSV."""
    if not verificar_sessao(request):
        return RedirectResponse("/admin/login", status_code=303)

    if ano is None:
        ano = datetime.now().year

    rows = fetchall("""
        SELECT c.nome, c.email, c.cpf, c.telefone, c.categoria,
               c.endereco, c.cep, c.cidade, c.estado, c.pais,
               c.profissao, c.instituicao,
               p.valor, p.metodo, p.status, p.data_pagamento
        FROM cadastrados c
        JOIN pagamentos p ON p.cadastrado_id = c.id
        WHERE p.ano = ?
        ORDER BY p.status DESC, c.nome
    """, (ano,))

    # Gera CSV em memoria
    output = StringIO()
    writer = csv.writer(output)
    writer.writerow([
        'Nome', 'Email', 'CPF', 'Telefone', 'Categoria',
        'Endereco', 'CEP', 'Cidade', 'Estado', 'Pais',
        'Profissao', 'Instituicao',
        'Valor', 'Metodo', 'Status', 'Data Pagamento'
    ])

    categorias_display = {
        'estudante': 'Estudante',
        'profissional_nacional': 'Profissional Brasil',
        'profissional_internacional': 'Profissional Internacional',
    }

    for r in rows:
        writer.writerow([
            r['nome'], r['email'], r['cpf'] or '', r['telefone'] or '',
            categorias_display.get(r['categoria'], r['categoria'] or ''),
            r['endereco'] or '', r['cep'] or '', r['cidade'] or '',
            r['estado'] or '', r['pais'] or '',
            r['profissao'] or '', r['instituicao'] or '',
            f"R$ {r['valor']/100:.2f}" if r['valor'] else '',
            r['metodo'] or '', r['status'] or '', r['data_pagamento'] or ''
        ])

    output.seek(0)
    filename = f"filiados_{ano}.csv"

    return StreamingResponse(
        iter([output.getvalue()]),
        media_type="text/csv",
        headers={"Content-Disposition": f"attachment; filename={filename}"}
    )
