<?php

/**
 * Plugin Ações Automáticas - lista das regras (ordem por arrastar, ligar/desligar, resumo, importar)
 */

Session::checkLoginUser();

$C = PluginAcoesautomaticasConfig::class;
$R = PluginAcoesautomaticasRegra::class;
$M = PluginAcoesautomaticasMotor::class;
$e = [$C, 'e'];
if (!$R::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

PluginAcoesautomaticasMenu::cabecalho('regra', 'Regras');
echo $C::assets();

$regras = $R::todas();
$editar = $R::canUpdate();
$ativas = count(array_filter($regras, fn($r) => (int) $r['is_active']));

echo '<div class="acoesautomaticas-pagina" data-acoesautomaticas-lista data-ajax="' . $e($C::url('ajax.php')) . '" data-token="' . $e($C::tokenCsrf()) . '">';

if ((string) $C::getConfig('duplicados_ativo') === '1') {
    echo '<div class="acoesautomaticas-alerta acoesautomaticas-alerta-info"><i class="ti ti-copy"></i><span><strong>Solucionar duplicados</strong> está ligado: na criação, chamados iguais a outro em andamento são solucionados antes das regras abaixo.'
        . ($C::ehAdmin() ? ' <a href="' . $e($C::url('config.form.php')) . '">Configurar</a>' : '') . '</span></div>';
}

echo '<div class="card acoesautomaticas-card"><div class="card-header"><h5><i class="ti ti-robot"></i> Regras <span class="acoesautomaticas-contagem">' . $ativas . ' ligada(s) de ' . count($regras) . '</span></h5>'
    . '<div class="acoesautomaticas-cab-acoes">'
    . '<input type="search" class="form-control form-control-sm acoesautomaticas-busca" placeholder="Filtrar regras..." data-acoesautomaticas-filtro>';
if ($R::canCreate()) {
    echo '<label class="btn btn-sm btn-ghost-secondary mb-0" title="Importar uma regra exportada (arquivo .json)"><i class="ti ti-upload"></i><span>Importar</span><input type="file" accept=".json,application/json" hidden data-acoesautomaticas-importar></label>'
        . '<a class="btn btn-sm acoesautomaticas-btn-principal" href="' . $e($C::url('regra.form.php')) . '"><i class="ti ti-plus"></i><span>Nova regra</span></a>';
}
echo '</div></div><div class="card-body p-0">';

if (!$regras) {
    echo '<div class="acoesautomaticas-vazio"><i class="ti ti-robot"></i><div><strong>Nenhuma regra ainda.</strong><p class="mb-0">Crie uma regra para atribuir, categorizar, responder, solucionar ou criar chamados filhos automaticamente.</p></div></div>';
} else {
    echo '<div class="table-responsive"><table class="table table-sm table-hover acoesautomaticas-tabela acoesautomaticas-regras mb-0"><thead><tr>'
        . ($editar ? '<th class="acoesautomaticas-col-mover"></th>' : '') . '<th>#</th><th>Regra</th><th>Quando</th><th>Condições</th><th>Ações</th><th class="text-end">Execuções</th><th class="text-center">Ligada</th><th></th></tr></thead><tbody data-acoesautomaticas-ordenar>';
    foreach ($regras as $i => $r) {
        $cond = $R::textoCondicoes($r);
        $acoes = $M::descrever($r);
        $url = $R::getFormURLWithID((int) $r['id']);
        echo '<tr data-id="' . (int) $r['id'] . '" data-search="' . $e(mb_strtolower($r['name'] . ' ' . implode(' ', $cond) . ' ' . implode(' ', $acoes))) . '"' . ((int) $r['is_active'] ? '' : ' class="acoesautomaticas-desligada"') . '>'
            . ($editar ? '<td class="acoesautomaticas-col-mover" title="Arraste para mudar a ordem"><i class="ti ti-grip-vertical"></i></td>' : '')
            . '<td class="acoesautomaticas-pequeno" data-posicao>' . ($i + 1) . '</td>'
            . '<td><a href="' . $e($url) . '" class="acoesautomaticas-nome">' . $e($r['name']) . '</a>'
            . ((int) $r['parar_apos_executar'] ? ' <span class="acoesautomaticas-selo acoesautomaticas-selo-sistema" title="Quando executa, as regras seguintes não rodam">para as seguintes</span>' : '')
            . ((int) $r['range_tipo'] ? '<div class="acoesautomaticas-pequeno"><i class="ti ti-clock"></i> ' . $e($R::textoJanela($r)) . ($M::naJanela($r) ? '' : ' · <span class="text-danger">fora da janela agora</span>') . '</div>' : '') . '</td>'
            . '<td class="acoesautomaticas-pequeno">' . $e($R::textoQuando($r)) . '</td>'
            . '<td class="acoesautomaticas-pequeno">' . ($cond ? $e(implode(' · ', $cond)) : '<em>todos os chamados</em>') . '</td>'
            . '<td>' . ($acoes ? implode(' ', array_map(fn($a) => '<span class="acoesautomaticas-etiqueta">' . $e($a) . '</span>', $acoes)) : '<span class="acoesautomaticas-pequeno text-danger">nenhuma ação ligada</span>') . '</td>'
            . '<td class="text-end text-nowrap">' . number_format((int) $r['contador_execucoes'], 0, ',', '.')
            . (!empty($r['ultima_execucao']) ? '<div class="acoesautomaticas-pequeno">' . $e(Html::convDateTime((string) $r['ultima_execucao'])) . '</div>' : '') . '</td>'
            . '<td class="text-center">' . ($editar ? '<div class="form-check form-switch acoesautomaticas-switch d-inline-block mb-0"><input class="form-check-input" type="checkbox" role="switch" data-acoesautomaticas-ligar="' . (int) $r['id'] . '"' . ((int) $r['is_active'] ? ' checked' : '') . ' title="Ligar ou desligar"></div>'
                : ((int) $r['is_active'] ? '<i class="ti ti-check text-success"></i>' : '—')) . '</td>'
            . '<td class="acoesautomaticas-col-acoes"><span class="acoesautomaticas-icones">'
            . '<a class="btn btn-sm btn-ghost-secondary" href="' . $e($url) . '" title="Editar"><i class="ti ti-edit"></i></a>'
            . '<a class="btn btn-sm btn-ghost-secondary" href="' . $e($url . '&forcetab=PluginAcoesautomaticasRegra$1') . '" title="Testar e executar"><i class="ti ti-flask"></i></a>'
            . ($R::canCreate() ? '<button type="button" class="btn btn-sm btn-ghost-secondary" data-acoesautomaticas-duplicar="' . (int) $r['id'] . '" title="Duplicar"><i class="ti ti-copy"></i></button>' : '')
            . '<a class="btn btn-sm btn-ghost-secondary" href="' . $e($C::url('regra.form.php', ['exportar' => (int) $r['id']])) . '" title="Exportar (JSON)"><i class="ti ti-download"></i></a>'
            . '</span></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';
echo '<div class="acoesautomaticas-rodape"><span class="acoesautomaticas-pequeno"><i class="ti ti-info-circle"></i> As regras são avaliadas de cima para baixo. O que uma regra faz não dispara outras regras; "para as seguintes" encerra a avaliação quando a regra executa.</span></div>';
echo '</div></div>';
Html::footer();
