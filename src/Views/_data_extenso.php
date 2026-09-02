<?php
// A DATA POR EXTENSO AO LADO DE CADA CAMPO.
//
// `<input type="date">` desenha no formato do NAVEGADOR, e nao no da pagina: o
// `lang="pt-BR"` nao alcanca o campo nativo, e nao ha atributo, CSS nem JS que
// force o formato. Chrome em ingles mostra 11/12/2026 para 12 de novembro — e
// quem edita nao tem como saber se leu certo. O dado nunca esteve errado (o
// campo SEMPRE envia AAAA-MM-DD, e e assim que fica no banco); o que faltava era
// a tela dizer sem ambiguidade qual data e aquela.
//
// Por extenso, e nao "12/11/2026": em dd/mm contra mm/dd a duvida continua de pe
// para todo dia menor que 13. "12 de novembro de 2026" nao se le de dois jeitos.
//
// Le o `value` do proprio input, que e ISO, e nao depende de como ele aparece.
?>
<script>
(function () {
    var meses = ['janeiro','fevereiro','março','abril','maio','junho',
                 'julho','agosto','setembro','outubro','novembro','dezembro'];
    var dias = ['domingo','segunda-feira','terça-feira','quarta-feira',
                'quinta-feira','sexta-feira','sábado'];

    function porExtenso(iso) {
        var p = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        if (!p) return '';
        var ano = +p[1], mes = +p[2], dia = +p[3];
        if (mes < 1 || mes > 12 || dia < 1 || dia > 31) return '';
        // Meio-dia UTC evita o fuso empurrar a data para o dia anterior.
        var d = new Date(Date.UTC(ano, mes - 1, dia, 12));
        if (d.getUTCMonth() + 1 !== mes || d.getUTCDate() !== dia) return '';
        return dias[d.getUTCDay()] + ', ' + dia + ' de ' + meses[mes - 1] + ' de ' + ano;
    }

    document.querySelectorAll('.data-extenso').forEach(function (eco) {
        var campo = document.getElementById(eco.getAttribute('data-para'));
        if (!campo) return;
        function atualizar() {
            var texto = porExtenso(campo.value);
            eco.textContent = texto;
            eco.style.display = texto ? '' : 'none';
        }
        campo.addEventListener('input', atualizar);
        campo.addEventListener('change', atualizar);
        atualizar();
    });
})();
</script>
