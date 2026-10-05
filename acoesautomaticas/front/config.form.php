<?php

/**
 * Plugin Ações Automáticas - configuração (marketplace e menu). Cada aba é um formulário com POST para
 * esta mesma página; o fluxo segue para o Html::header depois de salvar.
 */

Session::checkLoginUser();

global $DB;
$C = PluginAcoesautomaticasConfig::class;
$e = [$C, 'e'];
if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$abas = [
    'duplicados' => ['ti ti-copy', 'Solucionar duplicados'],
    'execucoes'  => ['ti ti-history', 'Execuções'],
    'acesso'     => ['ti ti-shield-lock', 'Acesso'],
];
$aba = (string) ($_POST['aba'] ?? $_GET['aba'] ?? 'duplicados');
if (!isset($abas[$aba])) {
    $aba = 'duplicados';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    switch ((string) ($_POST['save_action'] ?? '')) {
        case 'salvar_duplicados':
            $ativo = !empty($_POST['duplicados_ativo']);
            $usuario = max(0, (int) ($_POST['duplicados_users_id'] ?? 0));
            if ($ativo && $usuario <= 0) {
                Session::addMessageAfterRedirect('Escolha o técnico que soluciona os duplicados.', false, ERROR);
                $ativo = false;
            }
            $C::setConfig('duplicados_ativo', $ativo ? '1' : '0');
            $C::setConfig('duplicados_users_id', (string) $usuario);
            $C::setConfig('duplicados_itilcategories_id', (string) max(0, (int) ($_POST['duplicados_itilcategories_id'] ?? 0)));
            $C::setConfig('duplicados_texto_solucao', (string) ($_POST['duplicados_texto_solucao'] ?? ''));
            $C::setConfig('duplicados_mesma_entidade', empty($_POST['duplicados_mesma_entidade']) ? '0' : '1');
            $C::setConfig('duplicados_avisar_pai', empty($_POST['duplicados_avisar_pai']) ? '0' : '1');
            $C::setConfig('duplicados_dias', (string) max(0, min(3650, (int) ($_POST['duplicados_dias'] ?? 30))));
            Session::addMessageAfterRedirect('Configuração de duplicados salva.', false, INFO);
            break;

        case 'salvar_execucoes':
            $C::setConfig('logs_dias', (string) max(0, min(3650, (int) ($_POST['logs_dias'] ?? 90))));
            Session::addMessageAfterRedirect('Retenção das execuções salva.', false, INFO);
            break;
    }
}

PluginAcoesautomaticasMenu::cabecalho('config', 'Configuração');
echo $C::assets();

$card = fn(string $icone, string $titulo, string $corpo) => '<div class="card acoesautomaticas-card"><div class="card-header"><h5><i class="' . $icone . '"></i> ' . $e($titulo) . '</h5></div><div class="card-body">' . $corpo . '</div></div>';
$campo = fn(string $rotulo, string $controle, string $dica = '') => '<div class="acoesautomaticas-campo"><label>' . $e($rotulo) . '</label>' . $controle . ($dica !== '' ? '<small class="acoesautomaticas-dica">' . $dica . '</small>' : '') . '</div>';
$explicacao = fn(string $t) => '<p class="acoesautomaticas-explicacao"><i class="ti ti-info-circle"></i><span>' . $t . '</span></p>';
$inicio = fn(string $acao) => '<form method="post" action="' . $e($C::url('config.form.php')) . '" class="acoesautomaticas-form"><input type="hidden" name="save_action" value="' . $acao . '"><input type="hidden" name="aba" value="' . $aba . '" data-acoesautomaticas-aba-atual>';
$fim = '<div class="acoesautomaticas-rodape-form"><span></span><button type="submit" class="btn btn-sm acoesautomaticas-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar</span></button></div>' . Html::closeForm(false);
$chave = function (string $nome, string $rotulo) use ($C) {
    return '<input type="hidden" name="' . $nome . '" value="0"><div class="form-check form-switch acoesautomaticas-switch"><input class="form-check-input" type="checkbox" role="switch" id="aa-' . $nome . '" name="' . $nome . '" value="1"'
        . ((string) $C::getConfig($nome) === '1' ? ' checked' : '') . '><label class="form-check-label" for="aa-' . $nome . '">' . $rotulo . '</label></div>';
};

echo '<div class="acoesautomaticas-pagina" data-acoesautomaticas-config>';
echo '<ul class="nav nav-pills acoesautomaticas-subabas">';
foreach ($abas as $k => [$icone, $rotulo]) {
    echo '<li class="nav-item"><a href="#" class="nav-link' . ($k === $aba ? ' active' : '') . '" data-aba="' . $k . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
}
echo '</ul>';

// ------------------------------------------------------------ duplicados
echo '<div data-aba-painel="duplicados"' . ($aba === 'duplicados' ? '' : ' hidden') . '>' . $inicio('salvar_duplicados');
$vars = '';
foreach (['{id}', '{titulo}', '{link}', '{entidade}', '{categoria}'] as $v) {
    $vars .= '<code>' . $v . '</code> ';
}
echo $card('ti ti-copy', 'Solucionar duplicados automaticamente', $explicacao('Na criação de um chamado, antes de qualquer regra: se já existir um chamado <strong>em andamento</strong> (novo, em atendimento, planejado ou pendente) com o mesmo título e a mesma descrição, '
        . 'o novo é vinculado como filho do original, atribuído ao técnico escolhido e solucionado com o texto abaixo. As variáveis do texto se referem ao chamado <strong>original</strong>.')
    . $chave('duplicados_ativo', 'Solucionar duplicados')
    . '<div class="acoesautomaticas-grade">'
    . $campo('Técnico que soluciona *', (string) User::dropdown(['name' => 'duplicados_users_id', 'value' => (int) $C::getConfig('duplicados_users_id'), 'right' => 'own_ticket', 'display' => false, 'width' => '100%']))
    . $campo('Categoria do duplicado', (string) ITILCategory::dropdown(['name' => 'duplicados_itilcategories_id', 'value' => (int) $C::getConfig('duplicados_itilcategories_id'), 'display' => false, 'entity' => -1, 'width' => '100%']), 'Opcional.')
    . $campo('Procurar originais abertos nos últimos (dias)', '<input type="number" class="form-control form-control-sm" name="duplicados_dias" min="0" max="3650" value="' . (int) $C::getConfig('duplicados_dias') . '">', '0 = qualquer data.')
    . '</div>'
    . '<div class="acoesautomaticas-linha">' . $chave('duplicados_mesma_entidade', 'Só na mesma entidade') . $chave('duplicados_avisar_pai', 'Avisar no chamado original (acompanhamento)') . '</div>'
    . $campo('Texto da solução', (string) Html::textarea(['name' => 'duplicados_texto_solucao', 'value' => (string) $C::getConfig('duplicados_texto_solucao'), 'enable_richtext' => true, 'cols' => 100, 'rows' => 5, 'display' => false]), 'Variáveis: ' . $vars));
echo $fim . '</div>';

// ------------------------------------------------------------ execuções
echo '<div data-aba-painel="execucoes"' . ($aba === 'execucoes' ? '' : ' hidden') . '>' . $inicio('salvar_execucoes');
$total = countElementsInTable(PluginAcoesautomaticasLog::TABELA);
echo $card('ti ti-history', 'Registro das execuções', $explicacao('Cada execução (automática, manual ou de duplicado) fica registrada com as ações feitas e eventuais erros; o histórico do chamado também recebe uma linha. '
        . 'A tarefa automática "AcoesautomaticasManutencao" (de hora em hora) apaga os registros antigos e desliga regras cujo período terminou.')
    . '<div class="acoesautomaticas-grade">' . $campo('Guardar execuções por (dias)', '<input type="number" class="form-control form-control-sm" name="logs_dias" min="0" max="3650" value="' . (int) $C::getConfig('logs_dias') . '">', '0 = para sempre.')
    . $campo('Registros guardados', '<div class="acoesautomaticas-valor">' . number_format($total, 0, ',', '.') . ' execução(ões) · <a href="' . $e($C::url('log.php')) . '">ver</a></div>') . '</div>');
echo $fim . '</div>';

// ------------------------------------------------------------ acesso
echo '<div data-aba-painel="acesso"' . ($aba === 'acesso' ? '' : ' hidden') . '>';
$direitos = [];
foreach ($DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $C::DIREITO]]) as $r) {
    $direitos[(int) $r['profiles_id']] = (int) $r['rights'];
}
$colunas = [READ => 'Ver', CREATE => 'Criar', UPDATE => 'Editar e executar', PURGE => 'Excluir'];
$linhas = '';
foreach ($DB->request(['SELECT' => ['id', 'name', 'interface'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $p) {
    if ($p['interface'] === 'helpdesk') {
        continue;
    }
    $val = $direitos[(int) $p['id']] ?? 0;
    $linhas .= '<tr data-linha data-search="' . $e(mb_strtolower($p['name'])) . '"><td><a href="' . $e(Profile::getFormURLWithID((int) $p['id'])) . '&forcetab=PluginAcoesautomaticasProfile$1">' . $e($p['name']) . '</a></td>';
    foreach (array_keys($colunas) as $bit) {
        $linhas .= '<td class="text-center">' . (($val & $bit) === $bit ? '<i class="ti ti-check text-success"></i>' : '<span class="acoesautomaticas-pequeno">—</span>') . '</td>';
    }
    $linhas .= '</tr>';
}
$cab = '';
foreach ($colunas as $rotulo) {
    $cab .= '<th class="text-center">' . $e($rotulo) . '</th>';
}
echo $card('ti ti-shield-lock', 'Direitos por perfil', $explicacao('Os direitos são nativos do GLPI: edite na aba <strong>Ações automáticas</strong> de cada perfil (clique no nome). As regras agem em qualquer chamado, de qualquer entidade, '
        . 'independentemente de quem as configurou; as ações são registradas em nome de quem fez a alteração que disparou a regra.')
    . '<input type="text" class="form-control form-control-sm acoesautomaticas-busca mb-2" placeholder="Pesquisar perfil..." data-acoesautomaticas-busca-tabela>'
    . '<div class="table-responsive acoesautomaticas-tabela-caixa"><table class="table table-striped table-hover table-sm acoesautomaticas-tabela mb-0"><thead class="sticky-top"><tr><th>Perfil</th>' . $cab . '</tr></thead><tbody>' . $linhas . '</tbody></table></div>');
echo '</div>';

echo '</div>';
Html::footer();
