<?php

/**
 * Plugin Ações Automáticas - endpoint AJAX (sempre JSON, sempre POST).
 * ligar, ordenar, duplicar, importar, simular, previa, lote, limpar_logs
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno: ' . $erro['message']]);
    }
});

$C = PluginAcoesautomaticasConfig::class;
$R = PluginAcoesautomaticasRegra::class;
$M = PluginAcoesautomaticasMotor::class;
$responder = function (array $dados) use ($C): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = $C::tokenCsrf();
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$falhar = fn(string $mensagem) => $responder(['success' => false, 'mensagem' => $mensagem]);

if ((int) Session::getLoginUserID() <= 0) {
    $falhar('Sessão expirada. Recarregue a página.');
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $falhar('Requisição inválida.');
}
$acao = (string) ($_POST['action'] ?? '');
$regraDoPost = function (int $direito) use ($falhar): array {
    $r = new PluginAcoesautomaticasRegra();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0 || !$r->getFromDB($id)) {
        $falhar('Regra não encontrada.');
    }
    if (!$r->can($id, $direito)) {
        $falhar('Você não tem permissão para isso.');
    }
    return $r->fields;
};

try {
    switch ($acao) {
        case 'ligar':
            $f = $regraDoPost(UPDATE);
            $r = new PluginAcoesautomaticasRegra();
            $ok = $r->update(['id' => (int) $f['id'], 'is_active' => empty($_POST['ligada']) ? 0 : 1]);
            $responder(['success' => (bool) $ok, 'mensagem' => $ok ? (empty($_POST['ligada']) ? 'Regra desligada.' : 'Regra ligada.') : 'Não foi possível alterar a regra.']);
            // no break

        case 'ordenar':
            if (!$R::canUpdate()) {
                $falhar('Você não tem permissão para isso.');
            }
            global $DB;
            $ids = $C::ids($_POST['ids'] ?? []);
            foreach ($ids as $i => $id) {
                $DB->update($R::TABELA, ['ordem' => $i + 1], ['id' => $id]);
            }
            $R::limparCache();
            $responder(['success' => true, 'mensagem' => 'Ordem salva.']);
            // no break

        case 'duplicar':
            if (!$R::canCreate()) {
                $falhar('Você não tem permissão para isso.');
            }
            $f = $regraDoPost(READ);
            $novo = $R::importar($R::exportar((int) $f['id']), ' (cópia)');
            if (!is_int($novo)) {
                $falhar($novo);
            }
            Session::addMessageAfterRedirect('Regra duplicada (desligada).', false, INFO);
            $responder(['success' => true, 'url' => $R::getFormURLWithID($novo)]);
            // no break

        case 'importar':
            if (!$R::canCreate()) {
                $falhar('Você não tem permissão para isso.');
            }
            $arquivo = $_FILES['arquivo'] ?? null;
            if (!$arquivo || ($arquivo['error'] ?? 1) !== UPLOAD_ERR_OK || (int) $arquivo['size'] > 2 * 1024 * 1024) {
                $falhar('Envie um arquivo .json de até 2 MB.');
            }
            $dados = json_decode((string) file_get_contents((string) $arquivo['tmp_name']), true);
            $novo = is_array($dados) ? $R::importar($dados) : 'Arquivo JSON inválido.';
            if (!is_int($novo)) {
                $falhar($novo);
            }
            Session::addMessageAfterRedirect('Regra importada (desligada): revise e ligue quando estiver pronta.', false, INFO);
            $responder(['success' => true, 'url' => $R::getFormURLWithID($novo)]);
            // no break

        case 'simular':
            $f = $regraDoPost(READ);
            $responder(['success' => true] + $M::simular($f, (int) ltrim((string) ($_POST['ticket'] ?? ''), '#')));
            // no break

        case 'previa':
            $f = $regraDoPost(UPDATE);
            @set_time_limit(120);
            $responder(['success' => true] + $M::previa($f));
            // no break

        case 'lote':
            $f = $regraDoPost(UPDATE);
            @set_time_limit(120);
            $responder(['success' => true] + $M::lote($f, (int) ($_POST['apos'] ?? 0), 20));
            // no break

        case 'limpar_logs':
            if (!$C::ehAdmin()) {
                $falhar('Só administradores apagam execuções.');
            }
            $n = PluginAcoesautomaticasLog::limpar(max(1, (int) ($_POST['dias'] ?? 30)));
            Session::addMessageAfterRedirect(number_format($n, 0, ',', '.') . ' execução(ões) apagada(s).', false, INFO);
            $responder(['success' => true, 'apagadas' => $n]);
            // no break

        default:
            $falhar('Ação desconhecida.');
    }
} catch (\Glpi\Exception\RedirectException $e) {
    throw $e;
} catch (\Throwable $e) {
    Toolbox::logInFile('acoesautomaticas', 'Erro no ajax (' . $acao . '): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    $falhar('Erro: ' . $e->getMessage());
}
