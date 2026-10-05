<?php

/**
 * Plugin Ações Automáticas - execuções registradas, com filtros na URL e paginação no servidor
 */

Session::checkLoginUser();

$C = PluginAcoesautomaticasConfig::class;
$L = PluginAcoesautomaticasLog::class;
$e = [$C, 'e'];
if (!$L::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$f = $L::filtros($_GET);
$porPagina = (int) ($_GET['por_pagina'] ?? 50);
$porPagina = in_array($porPagina, [25, 50, 100, 200], true) ? $porPagina : 50;
$r = $L::listar($f, (int) ($_GET['pagina'] ?? 1), $porPagina);

PluginAcoesautomaticasMenu::cabecalho('log', 'Execuções');
echo $C::assets();

$regras = ['-1' => 'Todas', '0' => 'Solucionar duplicados / sistema'];
foreach (PluginAcoesautomaticasRegra::todas() as $rg) {
    $regras[(string) $rg['id']] = $rg['name'];
}
$opcoes = function (array $lista, string $atual) use ($e): string {
    $h = '';
    foreach ($lista as $v => $rotulo) {
        $h .= '<option value="' . $e($v) . '"' . ((string) $v === $atual ? ' selected' : '') . '>' . $e($rotulo) . '</option>';
    }
    return $h;
};

echo '<div class="acoesautomaticas-pagina" data-acoesautomaticas-logs data-ajax="' . $e($C::url('ajax.php')) . '" data-token="' . $e($C::tokenCsrf()) . '">';
echo '<form method="get" action="' . $e($C::url('log.php')) . '" class="card acoesautomaticas-card"><div class="card-body"><div class="acoesautomaticas-filtros">'
    . '<div class="acoesautomaticas-grupo"><span class="acoesautomaticas-rotulo-linha">Regra</span><select name="regra" class="form-select form-select-sm">' . $opcoes($regras, (string) $f['regra']) . '</select></div>'
    . '<div class="acoesautomaticas-grupo"><span class="acoesautomaticas-rotulo-linha">Tipo</span><select name="tipo" class="form-select form-select-sm">' . $opcoes(['' => 'Todos'] + $L::TIPOS, $f['tipo']) . '</select></div>'
    . '<div class="acoesautomaticas-grupo"><span class="acoesautomaticas-rotulo-linha">Chamado</span><input type="text" name="chamado" class="form-control form-control-sm acoesautomaticas-numero" value="' . ($f['chamado'] ?: '') . '" placeholder="#"></div>'
    . '<div class="acoesautomaticas-grupo"><span class="acoesautomaticas-rotulo-linha">De</span>' . Html::showDateField('de', ['value' => substr($f['de'], 0, 10), 'display' => false, 'maybeempty' => true])
    . '<span class="acoesautomaticas-rotulo-linha">até</span>' . Html::showDateField('ate', ['value' => substr($f['ate'], 0, 10), 'display' => false, 'maybeempty' => true]) . '</div>'
    . '<label class="acoesautomaticas-opcao"><input type="checkbox" class="acoesautomaticas-check" name="erros" value="1"' . ($f['erros'] ? ' checked' : '') . '> Só com erros</label>'
    . '<input type="hidden" name="por_pagina" value="' . $porPagina . '">'
    . '<div class="acoesautomaticas-acoes ms-auto"><a class="btn btn-sm btn-ghost-secondary" href="' . $e($C::url('log.php')) . '"><i class="ti ti-eraser"></i><span>Limpar</span></a>'
    . '<button type="submit" class="btn btn-sm acoesautomaticas-btn-principal"><i class="ti ti-filter"></i><span>Filtrar</span></button></div>'
    . '</div></div></form>';

$params = array_filter(['regra' => $f['regra'] >= 0 ? $f['regra'] : null, 'tipo' => $f['tipo'], 'chamado' => $f['chamado'] ?: null, 'erros' => $f['erros'] ? 1 : null, 'de' => substr($f['de'], 0, 10), 'ate' => substr($f['ate'], 0, 10), 'por_pagina' => $porPagina], fn($v) => $v !== null && $v !== '');
$link = fn(int $p) => $C::url('log.php', $params + ['pagina' => $p]);
$pag = '';
if ($r['paginas'] > 1) {
    $item = fn(int $p, string $rotulo, bool $ativo = false, bool $off = false) => '<li class="page-item' . ($ativo ? ' active' : '') . ($off ? ' disabled' : '') . '"><a class="page-link" href="' . $e($link($p)) . '">' . $rotulo . '</a></li>';
    $pag = '<ul class="pagination pagination-sm mb-0">' . $item($r['pagina'] - 1, '<i class="ti ti-chevron-left"></i>', false, $r['pagina'] <= 1);
    $ini = max(1, $r['pagina'] - 2);
    $fim = min($r['paginas'], $ini + 4);
    $ini = max(1, $fim - 4);
    if ($ini > 1) {
        $pag .= $item(1, '1') . ($ini > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
    }
    for ($i = $ini; $i <= $fim; $i++) {
        $pag .= $item($i, (string) $i, $i === $r['pagina']);
    }
    if ($fim < $r['paginas']) {
        $pag .= ($fim < $r['paginas'] - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') . $item($r['paginas'], (string) $r['paginas']);
    }
    $pag .= $item($r['pagina'] + 1, '<i class="ti ti-chevron-right"></i>', false, $r['pagina'] >= $r['paginas']) . '</ul>';
}
$porPag = '<label class="acoesautomaticas-pequeno d-inline-flex align-items-center gap-2 mb-0">Por página <select class="form-select form-select-sm" data-acoesautomaticas-por-pagina>';
foreach ([25, 50, 100, 200] as $n) {
    $porPag .= '<option value="' . $e($C::url('log.php', array_merge($params, ['por_pagina' => $n]))) . '"' . ($n === $porPagina ? ' selected' : '') . '>' . $n . '</option>';
}
$porPag .= '</select></label>';

echo '<div class="card acoesautomaticas-card"><div class="card-header"><h5><i class="ti ti-history"></i> Execuções <span class="acoesautomaticas-contagem">' . number_format($r['total'], 0, ',', '.') . '</span></h5>';
if ($C::ehAdmin()) {
    $dias = $C::inteiro('logs_dias', 0, 3650);
    echo '<div class="acoesautomaticas-cab-acoes"><span class="acoesautomaticas-pequeno">' . ($dias > 0 ? 'Guardadas por ' . $dias . ' dias' : 'Guardadas para sempre') . '</span>'
        . '<button type="button" class="btn btn-sm btn-ghost-danger" data-acoesautomaticas-limpar-logs data-acoesautomaticas-confirmar="Apagar as execuções com mais de 30 dias?"><i class="ti ti-trash"></i><span>Apagar com mais de 30 dias</span></button></div>';
}
echo '</div><div class="card-body p-0">' . $L::tabela($r['linhas'], true) . '</div>'
    . '<div class="acoesautomaticas-rodape"><span class="acoesautomaticas-pequeno">Página ' . $r['pagina'] . ' de ' . $r['paginas'] . '</span>' . $pag . $porPag . '</div></div>';
echo '</div>';
Html::footer();
