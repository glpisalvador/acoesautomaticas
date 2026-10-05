<?php

/**
 * Plugin Ações Automáticas - registro das execuções (automáticas, manuais, duplicados e sistema),
 * aba "Execuções" da regra, página de execuções e tarefa automática de manutenção.
 */
class PluginAcoesautomaticasLog extends CommonDBTM
{
    public const TABELA = 'glpi_plugin_acoesautomaticas_logs';

    public const TIPOS = [
        'execucao'  => 'Automática',
        'manual'    => 'Manual',
        'duplicado' => 'Duplicado',
        'sistema'   => 'Sistema',
        'alteracao' => 'Alteração da regra',
    ];

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Execuções' : 'Execução';
    }

    public static function canView(): bool
    {
        return PluginAcoesautomaticasRegra::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return PluginAcoesautomaticasConfig::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return PluginAcoesautomaticasConfig::ehAdmin();
    }

    public static function registrar(string $tipo, array $regra, ?Ticket $ticket, string $evento, array $acoes, array $erros): void
    {
        global $DB;
        $uid = (int) Session::getLoginUserID();
        $DB->insert(self::TABELA, [
            'tipo'          => $tipo,
            'regras_id'     => (int) ($regra['id'] ?? 0),
            'regra_nome'    => mb_substr((string) ($regra['name'] ?? ''), 0, 255),
            'tickets_id'    => $ticket ? (int) $ticket->getID() : 0,
            'ticket_nome'   => $ticket ? mb_substr((string) ($ticket->fields['name'] ?? ''), 0, 255) : '',
            'users_id'      => $uid,
            'usuario_nome'  => mb_substr($uid > 0 ? (string) getUserName($uid) : 'Sistema', 0, 255),
            'evento'        => mb_substr($evento, 0, 100),
            'detalhes'      => json_encode(['acoes' => array_values($acoes), 'erros' => array_values($erros)], JSON_UNESCAPED_UNICODE),
            'sucesso'       => $erros ? 0 : 1,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        // Também no histórico do chamado
        if ($ticket && $ticket->getID() > 0 && $acoes && !in_array($tipo, ['alteracao', 'sistema'], true)) {
            Log::history((int) $ticket->getID(), 'Ticket', [0, '', mb_substr('Ações automáticas (' . ($regra['name'] ?? '') . '): ' . implode(', ', $acoes), 0, 250)], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        }
    }

    // =====================================================================
    // Consulta
    // =====================================================================

    public static function filtros(array $in): array
    {
        $data = fn(string $v, string $hora) => preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) . $hora : '';
        return [
            'regra'   => is_numeric($in['regra'] ?? null) ? (int) $in['regra'] : -1,
            'tipo'    => isset(self::TIPOS[(string) ($in['tipo'] ?? '')]) ? (string) $in['tipo'] : '',
            'chamado' => max(0, (int) ltrim((string) ($in['chamado'] ?? ''), '#')),
            'erros'   => !empty($in['erros']),
            'de'      => $data((string) ($in['de'] ?? ''), ' 00:00:00'),
            'ate'     => $data((string) ($in['ate'] ?? ''), ' 23:59:59'),
        ];
    }

    private static function where(array $f): array
    {
        $w = [];
        if ($f['regra'] >= 0) {
            $w['regras_id'] = $f['regra'];
        }
        if ($f['tipo'] !== '') {
            $w['tipo'] = $f['tipo'];
        }
        if ($f['chamado'] > 0) {
            $w['tickets_id'] = $f['chamado'];
        }
        if ($f['erros']) {
            $w['sucesso'] = 0;
        }
        if ($f['de'] !== '') {
            $w[] = ['date_creation' => ['>=', $f['de']]];
        }
        if ($f['ate'] !== '') {
            $w[] = ['date_creation' => ['<=', $f['ate']]];
        }
        return $w;
    }

    public static function listar(array $f, int $pagina, int $porPagina): array
    {
        global $DB;
        $porPagina = max(10, $porPagina);
        $w = self::where($f);
        $total = countElementsInTable(self::TABELA, $w);
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $linhas = iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => $w, 'ORDER' => 'id DESC', 'START' => ($pagina - 1) * $porPagina, 'LIMIT' => $porPagina]), false);
        return ['total' => $total, 'pagina' => $pagina, 'paginas' => $paginas, 'linhas' => $linhas];
    }

    /** Tabela de execuções (página e aba da regra) */
    public static function tabela(array $linhas, bool $comRegra): string
    {
        $C = PluginAcoesautomaticasConfig::class;
        $e = [$C, 'e'];
        if (!$linhas) {
            return '<div class="acoesautomaticas-vazio"><i class="ti ti-history"></i><span>Nenhuma execução registrada.</span></div>';
        }
        $h = '<div class="table-responsive"><table class="table table-sm table-hover acoesautomaticas-tabela mb-0"><thead><tr><th>Data</th><th>Tipo</th>' . ($comRegra ? '<th>Regra</th>' : '')
            . '<th>Chamado</th><th>Evento</th><th>Ações</th><th>Por</th><th></th></tr></thead><tbody>';
        foreach ($linhas as $l) {
            $d = json_decode((string) $l['detalhes'], true) ?: [];
            $acoes = (array) ($d['acoes'] ?? []);
            $erros = (array) ($d['erros'] ?? []);
            $regra = (int) $l['regras_id'] > 0 ? '<a href="' . $e(PluginAcoesautomaticasRegra::getFormURLWithID((int) $l['regras_id'])) . '">' . $e($l['regra_nome']) . '</a>' : $e($l['regra_nome']);
            $chamado = (int) $l['tickets_id'] > 0 ? '<a href="' . $e(Ticket::getFormURLWithID((int) $l['tickets_id'])) . '">#' . (int) $l['tickets_id'] . '</a> <span class="acoesautomaticas-pequeno">' . $e(mb_strimwidth((string) $l['ticket_nome'], 0, 60, '…')) . '</span>' : '—';
            $h .= '<tr><td class="text-nowrap">' . $e(Html::convDateTime((string) $l['date_creation'])) . '</td>'
                . '<td><span class="acoesautomaticas-selo acoesautomaticas-selo-' . $e($l['tipo']) . '">' . $e(self::TIPOS[$l['tipo']] ?? $l['tipo']) . '</span></td>'
                . ($comRegra ? '<td>' . $regra . '</td>' : '')
                . '<td>' . $chamado . '</td><td class="acoesautomaticas-pequeno">' . $e($l['evento']) . '</td>'
                . '<td>' . ($acoes ? implode(' ', array_map(fn($a) => '<span class="acoesautomaticas-etiqueta">' . $e($a) . '</span>', $acoes)) : '<span class="acoesautomaticas-pequeno">—</span>')
                . ($erros ? '<div class="acoesautomaticas-erro-linha"><i class="ti ti-alert-triangle"></i> ' . $e(implode(' · ', $erros)) . '</div>' : '') . '</td>'
                . '<td class="text-nowrap acoesautomaticas-pequeno">' . $e($l['usuario_nome']) . '</td>'
                . '<td>' . ((int) $l['sucesso'] ? '<i class="ti ti-circle-check text-success" title="Sem erros"></i>' : '<i class="ti ti-alert-triangle text-danger" title="Com erros"></i>') . '</td></tr>';
        }
        return $h . '</tbody></table></div>';
    }

    public static function mostrarDaRegra(int $regraId): void
    {
        $C = PluginAcoesautomaticasConfig::class;
        $e = [$C, 'e'];
        echo $C::assets();
        $r = self::listar(self::filtros(['regra' => $regraId]), 1, 50);
        echo '<div class="acoesautomaticas-pagina"><div class="card acoesautomaticas-card"><div class="card-header"><h5><i class="ti ti-history"></i> Últimas execuções (' . number_format($r['total'], 0, ',', '.') . ')</h5>'
            . '<a class="btn btn-sm btn-ghost-secondary ms-auto" href="' . $e($C::url('log.php', ['regra' => $regraId])) . '"><i class="ti ti-list"></i><span>Ver todas com filtros</span></a></div>'
            . '<div class="card-body p-0">' . self::tabela($r['linhas'], false) . '</div></div></div>';
    }

    public static function limpar(int $dias): int
    {
        global $DB;
        if ($dias <= 0) {
            return 0;
        }
        $onde = ['date_creation' => ['<', date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'))]];
        $n = countElementsInTable(self::TABELA, $onde);
        if ($n > 0) {
            $DB->delete(self::TABELA, $onde);
        }
        return $n;
    }

    // =====================================================================
    // Tarefa automática
    // =====================================================================

    public static function cronInfo($name)
    {
        if ($name === 'AcoesautomaticasManutencao') {
            return ['description' => 'Ações automáticas: desliga regras com período encerrado e apaga execuções antigas'];
        }
        return [];
    }

    public static function cronAcoesautomaticasManutencao(CronTask $task): int
    {
        global $DB;
        $n = 0;
        foreach ($DB->request(['FROM' => PluginAcoesautomaticasRegra::TABELA, 'WHERE' => ['is_active' => 1, 'range_tipo' => 2, 'range_data_fim' => ['<', date('Y-m-d H:i:s')]]]) as $r) {
            $regra = new PluginAcoesautomaticasRegra();
            $regra->update(['id' => (int) $r['id'], 'is_active' => 0]);
            self::registrar('sistema', $r, null, 'Regra desligada: o período terminou em ' . Html::convDateTime((string) $r['range_data_fim']), [], []);
            $n++;
        }
        $n += self::limpar(PluginAcoesautomaticasConfig::inteiro('logs_dias', 0, 3650));
        $task->addVolume($n);
        return 1;
    }
}
