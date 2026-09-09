"""Converte a marca da organizacao, em SVG, no `.pdfpath` que a carteira usa.

    inkscape marca.svg --export-type=svg --export-plain-svg \
             --export-area-drawing --actions="select-all;object-to-path" \
             --export-filename=marca-plana.svg
    python3 scripts/svg_para_pdfpath.py marca-plana.svg \
             public/assets/img/logo-<sigla>-negativo.pdfpath

Existe porque a marca em PNG se desfaz na tela do celular -- ver
`Carteirinha::marca()` e `PilotisTcpdf::caminhoVetorial()`, que explicam por que
as saidas obvias (ImageSVG, ImageEps, reamostrar com GD) nao servem. O arquivo
gerado e so a geometria: quem desenha escolhe cor, posicao e tamanho.

O SVG precisa vir PLANO -- sem <use>, sem texto, so <path>. E o que a linha do
inkscape acima faz; o `object-to-path` copia os glifos para o corpo do desenho e
o <defs> original e descartado aqui (incluir os dois desenharia duas vezes).

Resolve os transform=translate/scale/matrix acumulados nos grupos, expande
H/V e as formas relativas, e escreve as coordenadas no espaco do proprio
desenho: origem no canto superior esquerdo, y para baixo. A primeira linha traz
`%% <largura> <altura>` dessa caixa.
"""
import re, sys
import xml.etree.ElementTree as ET

NUM = re.compile(r'[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?')
CMD = re.compile(r'([MmLlHhVvCcSsQqTtAaZz])')

def mul(m, n):
    a1,b1,c1,d1,e1,f1 = m; a2,b2,c2,d2,e2,f2 = n
    return (a1*a2+c1*b2, b1*a2+d1*b2, a1*c2+c1*d2, b1*c2+d1*d2,
            a1*e2+c1*f2+e1, b1*e2+d1*f2+f1)

def parse_transform(t):
    m = (1,0,0,1,0,0)
    for name, args in re.findall(r'(\w+)\s*\(([^)]*)\)', t or ''):
        v = [float(x) for x in NUM.findall(args)]
        if name == 'translate': m = mul(m, (1,0,0,1, v[0], v[1] if len(v)>1 else 0))
        elif name == 'scale':   m = mul(m, (v[0],0,0, v[1] if len(v)>1 else v[0], 0,0))
        elif name == 'matrix':  m = mul(m, tuple(v[:6]))
    return m

def apply(m, x, y):
    a,b,c,d,e,f = m
    return (a*x + c*y + e, b*x + d*y + f)

def tokens(d):
    parts = CMD.split(d)
    out, i = [], 1
    while i < len(parts):
        out.append((parts[i], [float(x) for x in NUM.findall(parts[i+1])]))
        i += 2
    return out

def to_pdf(d, m, prec=4):
    ops, cur, start = [], (0.0, 0.0), (0.0, 0.0)
    P = lambda x, y: '%s %s' % (round(apply(m,x,y)[0], prec), round(apply(m,x,y)[1], prec))
    for cmd, a in tokens(d):
        rel = cmd.islower(); c = cmd.upper()
        if c == 'M':
            for j in range(0, len(a), 2):
                x, y = (cur[0]+a[j], cur[1]+a[j+1]) if rel else (a[j], a[j+1])
                ops.append(P(x, y) + (' m' if j == 0 else ' l')); cur = (x, y)
                if j == 0: start = cur
        elif c in 'LT':
            for j in range(0, len(a), 2):
                x, y = (cur[0]+a[j], cur[1]+a[j+1]) if rel else (a[j], a[j+1])
                ops.append(P(x, y) + ' l'); cur = (x, y)
        elif c == 'H':
            for v in a:
                x = cur[0]+v if rel else v
                ops.append(P(x, cur[1]) + ' l'); cur = (x, cur[1])
        elif c == 'V':
            for v in a:
                y = cur[1]+v if rel else v
                ops.append(P(cur[0], y) + ' l'); cur = (cur[0], y)
        elif c == 'C':
            for j in range(0, len(a), 6):
                pts = []
                for k in range(0, 6, 2):
                    x, y = (cur[0]+a[j+k], cur[1]+a[j+k+1]) if rel else (a[j+k], a[j+k+1])
                    pts.append((x, y))
                ops.append(' '.join(P(*p) for p in pts) + ' c'); cur = pts[-1]
        elif c == 'Z':
            ops.append('h'); cur = start
        else:
            raise SystemExit('comando SVG nao previsto: ' + cmd)
    return ' '.join(ops)

def walk(el, m, saida):
    # <defs> guarda os glifos originais que o object-to-path ja copiou para o
    # corpo: incluir os dois desenharia a assinatura duas vezes, uma delas na
    # posicao errada.
    if el.tag.endswith('}defs'):
        return
    m = mul(m, parse_transform(el.get('transform')))
    if el.tag.endswith('}path') and el.get('d'):
        saida.append(to_pdf(el.get('d'), m))
    for f in el:
        walk(f, m, saida)

raiz = ET.parse(sys.argv[1]).getroot()
larg = float(NUM.search(raiz.get('width')).group())
alt  = float(NUM.search(raiz.get('height')).group())
saida = []
walk(raiz, (1,0,0,1,0,0), saida)
open(sys.argv[2], 'w').write('%%%% %.6f %.6f\n' % (larg, alt) + '\n'.join(saida) + '\n')
print('caminhos:', len(saida), '| caixa:', larg, 'x', alt)
