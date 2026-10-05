<?php

/**
 * Plugin Ações Automáticas - formulário da regra (add, update, purge nativos), chamados filhos e exportação
 */

Session::checkLoginUser();

$C = PluginAcoesautomaticasConfig::class;
$R = PluginAcoesautomaticasRegra::class;
$regra = new PluginAcoesautomaticasRegra();
$lista = $C::url('regra.php');

// Exportação em JSON
if (isset($_GET['exportar'])) {
    $id = (int) $_GET['exportar'];
    $regra->check($id, READ);
    $dados = $R::exportar($id);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $nome = 'regra_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $regra->fields['name']) ?: 'acao') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . trim($nome, '_') . '"');
    echo json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_POST['add'])) {
    $regra->check(-1, CREATE, $_POST);
    $id = $regra->add($_POST);
    if ($id) {
        Session::addMessageAfterRedirect('Regra criada.', false, INFO);
        Html::redirect($regra->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $regra->check((int) $_POST['id'], UPDATE);
    $regra->update($_POST);
    Html::back();
} elseif (isset($_POST['purge']) || isset($_POST['delete'])) {
    $regra->check((int) $_POST['id'], PURGE);
    $regra->delete($_POST, true);
    Session::addMessageAfterRedirect('Regra excluída.', false, INFO);
    Html::redirect($lista);
} elseif (isset($_POST['salvar_filho'])) {
    $regra->check((int) $_POST['id'], UPDATE);
    if (PluginAcoesautomaticasFilho::salvar((int) $_POST['id'], (int) ($_POST['filho_id'] ?? 0), $_POST) > 0) {
        Session::addMessageAfterRedirect('Chamado filho salvo.', false, INFO);
    }
    Html::back();
} elseif (isset($_POST['excluir_filho'])) {
    $regra->check((int) $_POST['id'], UPDATE);
    if (PluginAcoesautomaticasFilho::excluir((int) $_POST['id'], (int) ($_POST['filho_id'] ?? 0))) {
        Session::addMessageAfterRedirect('Chamado filho removido.', false, INFO);
    }
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $regra->check($id, READ);
} else {
    $regra->check(-1, CREATE);
}

PluginAcoesautomaticasMenu::cabecalho('regra', $id > 0 ? (string) $regra->fields['name'] : 'Nova regra');
if ($id > 0) {
    $regra->display(['id' => $id]);
} else {
    $regra->showForm(0);
}
Html::footer();
