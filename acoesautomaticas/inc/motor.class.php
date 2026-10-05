<?php

use Glpi\DBAL\QuerySubQuery;

/**
 * Plugin Ações Automáticas - motor das regras.
 * Recebe os eventos dos chamados (hooks), confere janela de execução e condições de cada regra ativa
 * (na ordem), executa as ações com as classes nativas do GLPI (histórico e notificações preservados),
 * resolve duplicados na criação, testa uma regra contra um chamado e executa regras manualmente em lotes.
 */
class PluginAcoesautomaticasMotor
{
    /** Eventos de atualização: chave => rótulo */
    public const EVENTOS = [
        'status'           => 'Mudança de status',
        'categoria'        => 'Categoria alterada',
        'prioridade'       => 'Prioridade alterada',
        'acompanhamento'   => 'Acompanhamento adicionado',
        'tarefa'           => 'Tarefa adicionada',
        'solucao'          => 'Solução adicionada',
        'tecnico'          => 'Técnico atribuído',
        'grupo_tecnico'    => 'Grupo técnico atribuído',
        'requerente'       => 'Requerente adicionado',
        'observador'       => 'Observador adicionado',
        'grupo_observador' => 'Grupo observador adicionado',
        'outra'            => 'Outra alteração do chamado',
    ];

    /** Nomes usados pela 1.x */
    private const EVENTOS_ANTIGOS = [
        'mudanca_status'              => 'status',
        'followup_adicionado'         => 'acompanhamento',
        'tecnico_atribuido'           => 'tecnico',
        'requerente_adicionado'       => 'requerente',
        'observador_adicionado'       => 'observador',
        'grupo_tecnico_atribuido'     => 'grupo_tecnico',
        'grupo_observador_adicionado' => 'grupo_observador',
        'atualizacao'                 => 'outra',
    ];

    /** >0 enquanto o plugin executa ações: o que ele mesmo grava não dispara regras de novo */
    private static int $executando = 0;
    /** >0 durante a criação de um chamado: atores e acompanhamentos da criação não contam como atualização */
    private static int $criando = 0;

    public static function eventos($valor): array
    {
        $lista = is_array($valor) ? $valor : (json_decode((string) $valor, true) ?: []);
        $saida = [];
        foreach ($lista as $e) {
            $e = self::EVENTOS_ANTIGOS[$e] ?? $e;
            if (isset(self::EVENTOS[$e])) {
                $saida[] = $e;
            }
        }
        return array_values(array_unique($saida));
    }

    // =====================================================================
    // Hooks
    // =====================================================================

    public static function antesDeCriar(Ticket $item): void
    {
        self::$criando++;
    }

    public static function aoCriarChamado(Ticket $item): void
    {
        self::$criando = max(0, self::$criando - 1);
        if (self::$executando > 0 || $item->getID() <= 0) {
            return;
        }
        $ticket = new Ticket();
        if (!$ticket->getFromDB($item->getID())) {
            return;
        }
        if (self::duplicados($ticket)) {
            return;
        }
        self::disparar($ticket, 'criacao', []);
    }

    public static function aoAtualizarChamado(Ticket $item): void
    {
        if (self::$executando > 0 || self::$criando > 0) {
            return;
        }
        $eventos = [];
        // Campos que o próprio GLPI ajusta (datas, tempos, último a alterar) não contam como alteração
        $tecnicos = ['date_mod', 'users_id_lastupdater', 'takeintoaccountdate', 'takeintoaccount_delay_stat', 'actiontime', 'waiting_duration',
            'begin_waiting_date', 'solvedate', 'closedate', 'close_delay_stat', 'solve_delay_stat', 'sla_waiting_duration', 'ola_waiting_duration',
            'internal_time_to_own', 'internal_time_to_resolve', 'time_to_own', 'time_to_resolve', 'global_validation'];
        $antes = array_diff_key($item->oldvalues ?? [], array_flip($tecnicos));
        if (array_key_exists('status', $antes)) {
            $eventos[] = 'status';
        }
        if (array_key_exists('itilcategories_id', $antes)) {
            $eventos[] = 'categoria';
        }
        if (array_key_exists('priority', $antes)) {
            $eventos[] = 'prioridade';
        }
        if (!$eventos) {
            if (!$antes) {
                return;
            }
            $eventos[] = 'outra';
        }
        self::dispararPorId((int) $item->getID(), $eventos);
    }

    public static function aoAdicionarAcompanhamento(ITILFollowup $item): void
    {
        if (($item->fields['itemtype'] ?? '') === 'Ticket') {
            self::dispararSeAtualizacao((int) $item->fields['items_id'], ['acompanhamento']);
        }
    }

    public static function aoAdicionarTarefa(TicketTask $item): void
    {
        self::dispararSeAtualizacao((int) ($item->fields['tickets_id'] ?? 0), ['tarefa']);
    }

    public static function aoAdicionarSolucao(ITILSolution $item): void
    {
        if (($item->fields['itemtype'] ?? '') === 'Ticket') {
            self::dispararSeAtualizacao((int) $item->fields['items_id'], ['solucao']);
        }
    }

    public static function aoAdicionarUsuario(Ticket_User $item): void
    {
        $evento = match ((int) ($item->fields['type'] ?? 0)) {
            CommonITILActor::ASSIGN    => 'tecnico',
            CommonITILActor::REQUESTER => 'requerente',
            CommonITILActor::OBSERVER  => 'observador',
            default                    => '',
        };
        if ($evento !== '') {
            self::dispararSeAtualizacao((int) ($item->fields['tickets_id'] ?? 0), [$evento]);
        }
    }

    public static function aoAdicionarGrupo(Group_Ticket $item): void
    {
        $evento = match ((int) ($item->fields['type'] ?? 0)) {
            CommonITILActor::ASSIGN   => 'grupo_tecnico',
            CommonITILActor::OBSERVER => 'grupo_observador',
            default                   => '',
        };
        if ($evento !== '') {
            self::dispararSeAtualizacao((int) ($item->fields['tickets_id'] ?? 0), [$evento]);
        }
    }

    private static function dispararSeAtualizacao(int $ticketId, array $eventos): void
    {
        if (self::$executando > 0 || self::$criando > 0 || $ticketId <= 0) {
            return;
        }
        self::dispararPorId($ticketId, $eventos);
    }

    private static function dispararPorId(int $ticketId, array $eventos): void
    {
        $ticket = new Ticket();
        if ($ticket->getFromDB($ticketId) && !(int) $ticket->fields['is_deleted']) {
            self::disparar($ticket, 'atualizacao', $eventos);
        }
    }

    // =====================================================================
    // Processamento
    // =====================================================================

    /** Regras ativas, na ordem, aplicadas a um chamado num momento ('criacao' ou 'atualizacao') */
    public static function disparar(Ticket $ticket, string $momento, array $eventos): array
    {
        $executadas = [];
        foreach (PluginAcoesautomaticasRegra::ativas() as $regra) {
            $em = (int) $regra['executar_em'];
            if (($momento === 'criacao' && $em === 1) || ($momento === 'atualizacao' && $em === 0)) {
                continue;
            }
            if ($momento === 'atualizacao') {
                $esperados = self::eventos($regra['tipos_atualizacao']);
                if ($esperados && !array_intersect($esperados, $eventos)) {
                    continue;
                }
            }
            if (!self::naJanela($regra)) {
                continue;
            }
            // Recarrega: a regra anterior pode ter mudado o chamado
            if (!$ticket->getFromDB($ticket->getID()) || (int) $ticket->fields['is_deleted']) {
                break;
            }
            if (!self::verificar($regra, $ticket)['ok']) {
                continue;
            }
            $r = self::executar($regra, $ticket);
            $rotulo = $momento === 'criacao' ? 'Criação' : 'Atualização: ' . implode(', ', array_map(fn($e) => self::EVENTOS[$e] ?? $e, $eventos));
            PluginAcoesautomaticasLog::registrar('execucao', $regra, $ticket, $rotulo, $r['acoes'], $r['erros']);
            $executadas[] = (int) $regra['id'];
            if ($r['parar'] || (int) $regra['parar_apos_executar']) {
                break;
            }
        }
        return $executadas;
    }

    /** Janela de execução: sempre, horário diário ou período de datas */
    public static function naJanela(array $regra, ?int $agora = null): bool
    {
        $agora ??= time();
        switch ((int) $regra['range_tipo']) {
            case 1:
                if (empty($regra['range_hora_inicio']) || empty($regra['range_hora_fim'])) {
                    return true;
                }
                $seg = fn(string $h) => (int) substr($h, 0, 2) * 3600 + (int) substr($h, 3, 2) * 60;
                $atual = (int) date('H', $agora) * 3600 + (int) date('i', $agora) * 60;
                $ini = $seg((string) $regra['range_hora_inicio']);
                $fim = $seg((string) $regra['range_hora_fim']);
                return $ini <= $fim ? ($atual >= $ini && $atual <= $fim) : ($atual >= $ini || $atual <= $fim);
            case 2:
                $ini = empty($regra['range_data_inicio']) ? null : strtotime((string) $regra['range_data_inicio']);
                $fim = empty($regra['range_data_fim']) ? null : strtotime((string) $regra['range_data_fim']);
                return !($ini && $agora < $ini) && !($fim && $agora > $fim);
        }
        return true;
    }

    // =====================================================================
    // Condições
    // =====================================================================

    /** Texto comparável: sem HTML, sem acentos, minúsculo, espaços normalizados */
    public static function normalizar(?string $texto): string
    {
        $t = (string) preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/td)\b[^>]*>/i', ' ', (string) $texto);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);
        $sem = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        if (is_string($sem) && $sem !== '') {
            $t = $sem;
        }
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower($t)));
    }

    /** Trechos de um critério de texto ("a || b" exige os dois) */
    public static function trechos(?string $criterio): array
    {
        $lista = [];
        foreach (explode('||', (string) $criterio) as $p) {
            $p = self::normalizar($p);
            if (mb_strlen($p) >= 2) {
                $lista[] = $p;
            }
        }
        return array_values(array_unique($lista));
    }

    private static function contemTodos(string $texto, array $trechos): bool
    {
        foreach ($trechos as $t) {
            if (!str_contains($texto, $t)) {
                return false;
            }
        }
        return true;
    }

    /** Atores do chamado: usuarios[tipo] e grupos[tipo] */
    public static function atores(int $ticketId): array
    {
        global $DB;
        $a = ['usuarios' => [1 => [], 2 => [], 3 => []], 'grupos' => [1 => [], 2 => [], 3 => []]];
        foreach ($DB->request(['SELECT' => ['users_id', 'type'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $ticketId]]) as $r) {
            $a['usuarios'][(int) $r['type']][] = (int) $r['users_id'];
        }
        foreach ($DB->request(['SELECT' => ['groups_id', 'type'], 'FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => $ticketId]]) as $r) {
            $a['grupos'][(int) $r['type']][] = (int) $r['groups_id'];
        }
        return $a;
    }

    /** Entidades aceitas pela regra (com filhas quando marcado) */
    public static function entidadesDaRegra(array $regra): array
    {
        $lista = PluginAcoesautomaticasConfig::ids($regra['cond_entidades'] ?? '', true);
        if (!$lista || !(int) $regra['cond_subentidades']) {
            return $lista;
        }
        $todas = [];
        foreach ($lista as $id) {
            $todas = array_merge($todas, array_map('intval', getSonsOf('glpi_entities', $id)));
        }
        return array_values(array_unique($todas));
    }

    /**
     * Confere as condições. Retorna ['ok' => bool, 'itens' => [[rótulo, ok, detalhe]]].
     * Condição vazia não restringe; todas as preenchidas precisam bater.
     */
    public static function verificar(array $regra, Ticket $ticket, bool $todas = false): array
    {
        global $DB;
        $f = $ticket->fields;
        $itens = [];
        $ok = true;
        $atores = null;
        $nome = fn(string $tabela, array $ids) => implode(', ', array_map(fn($i) => Dropdown::getDropdownName($tabela, $i), $ids));
        $conferir = function (string $rotulo, bool $passou, string $detalhe) use (&$itens, &$ok, $todas): bool {
            $itens[] = [$rotulo, $passou, $detalhe];
            if (!$passou) {
                $ok = false;
            }
            return $passou || $todas;
        };
        $lista = fn(string $campo) => PluginAcoesautomaticasConfig::ids($regra[$campo] ?? '');
        $atoresDe = function () use (&$atores, $ticket) {
            return $atores ??= self::atores((int) $ticket->getID());
        };

        $simples = [
            'cond_tipos'       => ['Tipo', 'type', fn($v) => Ticket::getTicketTypeName($v)],
            'cond_status'      => ['Status', 'status', fn($v) => Ticket::getStatus($v)],
            'cond_prioridades' => ['Prioridade', 'priority', fn($v) => CommonITILObject::getPriorityName($v)],
            'cond_urgencias'   => ['Urgência', 'urgency', fn($v) => CommonITILObject::getUrgencyName($v)],
            'cond_categorias'  => ['Categoria', 'itilcategories_id', fn($v) => $v ? Dropdown::getDropdownName('glpi_itilcategories', $v) : '(sem categoria)'],
            'cond_origens'     => ['Origem', 'requesttypes_id', fn($v) => $v ? Dropdown::getDropdownName('glpi_requesttypes', $v) : '(sem origem)'],
        ];
        foreach ($simples as $campo => [$rotulo, $coluna, $texto]) {
            $esperado = $lista($campo);
            if ($esperado) {
                $valor = (int) ($f[$coluna] ?? 0);
                if (!$conferir($rotulo, in_array($valor, $esperado, true), 'chamado: ' . $texto($valor) . ' · regra: ' . implode(', ', array_map($texto, $esperado)))) {
                    return ['ok' => false, 'itens' => $itens];
                }
            }
        }
        $entidades = self::entidadesDaRegra($regra);
        if ($entidades) {
            $valor = (int) $f['entities_id'];
            if (!$conferir('Entidade', in_array($valor, $entidades, true), 'chamado: ' . Dropdown::getDropdownName('glpi_entities', $valor)
                . ' · regra: ' . $nome('glpi_entities', PluginAcoesautomaticasConfig::ids($regra['cond_entidades'], true)) . ((int) $regra['cond_subentidades'] ? ' (com subentidades)' : ''))) {
                return ['ok' => false, 'itens' => $itens];
            }
        }
        $porAtor = [
            'cond_requerentes'         => ['Requerente', 'usuarios', CommonITILActor::REQUESTER, 'glpi_users'],
            'cond_grupos_observadores' => ['Grupo observador', 'grupos', CommonITILActor::OBSERVER, 'glpi_groups'],
            'cond_grupos_atribuidos'   => ['Grupo atribuído', 'grupos', CommonITILActor::ASSIGN, 'glpi_groups'],
        ];
        foreach ($porAtor as $campo => [$rotulo, $chave, $papel, $tabela]) {
            $esperado = $lista($campo);
            if ($esperado) {
                $tem = $atoresDe()[$chave][$papel];
                $passou = (bool) array_intersect($esperado, $tem);
                $detalhe = 'chamado: ' . ($tem ? ($tabela === 'glpi_users' ? implode(', ', array_map('getUserName', $tem)) : $nome($tabela, $tem)) : 'nenhum')
                    . ' · regra: ' . ($tabela === 'glpi_users' ? implode(', ', array_map('getUserName', $esperado)) : $nome($tabela, $esperado));
                if (!$conferir($rotulo, $passou, $detalhe)) {
                    return ['ok' => false, 'itens' => $itens];
                }
            }
        }
        if ((int) $regra['cond_sem_tecnico']) {
            $a = $atoresDe();
            $semNinguem = !$a['usuarios'][CommonITILActor::ASSIGN] && !$a['grupos'][CommonITILActor::ASSIGN];
            if (!$conferir('Sem técnico nem grupo atribuído', $semNinguem, $semNinguem ? 'ninguém atribuído' : 'o chamado já tem atribuição')) {
                return ['ok' => false, 'itens' => $itens];
            }
        }
        $textos = [
            'titulo_contem'    => ['Título contém', $f['name'] ?? ''],
            'descricao_contem' => ['Descrição contém', $f['content'] ?? ''],
        ];
        foreach ($textos as $campo => [$rotulo, $valor]) {
            $trechos = self::trechos($regra[$campo] ?? '');
            if ($trechos && !$conferir($rotulo, self::contemTodos(self::normalizar($valor), $trechos), 'procurado: ' . implode(' + ', $trechos))) {
                return ['ok' => false, 'itens' => $itens];
            }
        }
        $proibidos = self::trechos($regra['titulo_nao_contem'] ?? '');
        if ($proibidos) {
            $titulo = self::normalizar($f['name'] ?? '');
            $achou = array_values(array_filter($proibidos, fn($t) => str_contains($titulo, $t)));
            if (!$conferir('Título não contém', !$achou, $achou ? 'encontrado: ' . implode(', ', $achou) : 'nenhum dos termos')) {
                return ['ok' => false, 'itens' => $itens];
            }
        }
        $trechos = self::trechos($regra['followup_contem'] ?? '');
        if ($trechos) {
            $achou = false;
            foreach ($DB->request(['SELECT' => ['content'], 'FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => (int) $ticket->getID()], 'ORDER' => 'id DESC']) as $r) {
                if (self::contemTodos(self::normalizar($r['content']), $trechos)) {
                    $achou = true;
                    break;
                }
            }
            if (!$conferir('Acompanhamento contém', $achou, 'procurado: ' . implode(' + ', $trechos))) {
                return ['ok' => false, 'itens' => $itens];
            }
        }
        return ['ok' => $ok, 'itens' => $itens];
    }

    // =====================================================================
    // Ações
    // =====================================================================

    /** Variáveis dos textos ({id}, {titulo}, ...) */
    public static function substituir(string $texto, Ticket $ticket, array $regra): string
    {
        global $CFG_GLPI;
        $f = $ticket->fields;
        $requerentes = array_map('getUserName', self::atores((int) $ticket->getID())['usuarios'][CommonITILActor::REQUESTER]);
        $url = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/front/ticket.form.php?id=' . (int) $ticket->getID();
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        return strtr($texto, [
            '{id}'         => (string) (int) $ticket->getID(),
            '{titulo}'     => $e($f['name'] ?? ''),
            '{requerente}' => $e(implode(', ', $requerentes)),
            '{entidade}'   => $e(Dropdown::getDropdownName('glpi_entities', (int) $f['entities_id'])),
            '{categoria}'  => $e((int) $f['itilcategories_id'] ? Dropdown::getDropdownName('glpi_itilcategories', (int) $f['itilcategories_id']) : ''),
            '{status}'     => $e(Ticket::getStatus((int) $f['status'])),
            '{link}'       => '<a href="' . $e($url) . '">' . $e($url) . '</a>',
            '{regra}'      => $e($regra['name'] ?? ''),
        ]);
    }

    private static function temTexto(?string $html): bool
    {
        return trim(str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags((string) $html, '<img>'), ENT_QUOTES, 'UTF-8'))) !== '';
    }

    /** Ações que a regra executaria, em texto (lista e teste) */
    public static function descrever(array $regra): array
    {
        $u = fn($id) => (int) $id > 0 ? getUserName((int) $id) : '?';
        $g = fn($id) => (int) $id > 0 ? Dropdown::getDropdownName('glpi_groups', (int) $id) : '?';
        $c = fn($id) => (int) $id > 0 ? Dropdown::getDropdownName('glpi_itilcategories', (int) $id) : '';
        $l = [];
        if ((int) $regra['acao_categorizar']) {
            $l[] = 'Categorizar: ' . $c($regra['acao_categorizar_itilcategories_id']);
        }
        if ((int) $regra['acao_prioridade']) {
            $l[] = 'Prioridade: ' . CommonITILObject::getPriorityName((int) $regra['acao_prioridade_valor']);
        }
        if ((int) $regra['acao_urgencia']) {
            $l[] = 'Urgência: ' . CommonITILObject::getUrgencyName((int) $regra['acao_urgencia_valor']);
        }
        if ((int) $regra['acao_grupo_tecnico']) {
            $l[] = 'Grupo técnico: ' . $g($regra['acao_grupo_tecnico_groups_id']);
        }
        if ((int) $regra['acao_atender'] && !(int) $regra['acao_solucionar'] && !(int) $regra['acao_pendente']) {
            $l[] = 'Atender: ' . $u($regra['acao_atender_users_id']) . ((int) $regra['acao_atender_itilcategories_id'] ? ' (' . $c($regra['acao_atender_itilcategories_id']) . ')' : '');
        }
        if ((int) $regra['acao_observador']) {
            $l[] = 'Observador: ' . $u($regra['acao_observador_users_id']);
        }
        if ((int) $regra['acao_grupo_observador']) {
            $l[] = 'Grupo observador: ' . $g($regra['acao_grupo_observador_groups_id']);
        }
        if ((int) $regra['acao_followup']) {
            $l[] = 'Acompanhamento ' . ((int) $regra['acao_followup_privado'] ? 'privado' : 'público');
        }
        if ((int) $regra['acao_tickets_filhos']) {
            $l[] = 'Chamados filhos: ' . count(PluginAcoesautomaticasFilho::daRegra((int) $regra['id']));
        }
        if ((int) $regra['acao_validacao']) {
            $l[] = 'Pedir validação a ' . $u($regra['acao_validacao_users_id']);
        }
        if ((int) $regra['acao_status'] && !(int) $regra['acao_solucionar'] && !(int) $regra['acao_pendente']) {
            $l[] = 'Status: ' . Ticket::getStatus((int) $regra['acao_status_valor']);
        }
        if ((int) $regra['acao_solucionar']) {
            $l[] = 'Solucionar como ' . $u($regra['acao_solucionar_users_id']);
        } elseif ((int) $regra['acao_pendente']) {
            $l[] = 'Pendente com ' . $u($regra['acao_pendente_users_id']) . ((int) $regra['acao_pendente_pendingreasons_id'] ? ' (' . Dropdown::getDropdownName('glpi_pendingreasons', (int) $regra['acao_pendente_pendingreasons_id']) . ')' : '');
        }
        if ((int) $regra['acao_excluir']) {
            $l[] = (int) $regra['acao_excluir_purgar'] ? 'Excluir definitivamente' : 'Mover para a lixeira';
        }
        return $l;
    }

    /**
     * Executa as ações da regra no chamado. Retorna ['acoes' => [...], 'erros' => [...], 'parar' => bool].
     * Cada ação roda isolada: um erro não impede as demais.
     */
    public static function executar(array $regra, Ticket $ticket): array
    {
        $id = (int) $ticket->getID();
        $acoes = [];
        $erros = [];
        $parar = false;
        $passo = function (string $nome, callable $fn) use (&$acoes, &$erros, $ticket, $id): void {
            try {
                if ($fn() !== false) {
                    $acoes[] = $nome;
                }
            } catch (\Throwable $e) {
                $erros[] = $nome . ': ' . $e->getMessage();
            }
            $ticket->getFromDB($id);
        };
        $txt = fn(?string $t) => self::substituir((string) $t, $ticket, $regra);
        $finaliza = (int) $regra['acao_solucionar'] || (int) $regra['acao_pendente'];

        self::$executando++;
        try {
            if ((int) $regra['acao_categorizar'] && (int) $regra['acao_categorizar_itilcategories_id'] > 0) {
                $passo('Categorizar', fn() => self::atualizar($ticket, ['itilcategories_id' => (int) $regra['acao_categorizar_itilcategories_id']]));
            }
            if ((int) $regra['acao_prioridade']) {
                $passo('Prioridade', fn() => self::atualizar($ticket, ['priority' => max(1, min(6, (int) $regra['acao_prioridade_valor']))]));
            }
            if ((int) $regra['acao_urgencia']) {
                $passo('Urgência', fn() => self::atualizar($ticket, ['urgency' => max(1, min(5, (int) $regra['acao_urgencia_valor']))]));
            }
            if ((int) $regra['acao_grupo_tecnico'] && (int) $regra['acao_grupo_tecnico_groups_id'] > 0) {
                $passo('Grupo técnico', fn() => self::grupo($id, (int) $regra['acao_grupo_tecnico_groups_id'], CommonITILActor::ASSIGN));
            }
            if ((int) $regra['acao_atender'] && (int) $regra['acao_atender_users_id'] > 0 && !$finaliza) {
                $passo('Atender', fn() => self::atender($ticket, (int) $regra['acao_atender_users_id'], (int) $regra['acao_atender_itilcategories_id'], (bool) (int) $regra['acao_atender_trocar_observador']));
            }
            if ((int) $regra['acao_observador'] && (int) $regra['acao_observador_users_id'] > 0) {
                $passo('Observador', fn() => self::usuario($id, (int) $regra['acao_observador_users_id'], CommonITILActor::OBSERVER));
            }
            if ((int) $regra['acao_grupo_observador'] && (int) $regra['acao_grupo_observador_groups_id'] > 0) {
                $passo('Grupo observador', fn() => self::grupo($id, (int) $regra['acao_grupo_observador_groups_id'], CommonITILActor::OBSERVER));
            }
            if ((int) $regra['acao_followup'] && self::temTexto($regra['acao_followup_texto'])) {
                $passo('Acompanhamento', fn() => self::acompanhamento($id, $txt($regra['acao_followup_texto']), (bool) (int) $regra['acao_followup_privado']));
            }
            if ((int) $regra['acao_tickets_filhos']) {
                $passo('Chamados filhos', fn() => PluginAcoesautomaticasFilho::criar($ticket, $regra) > 0 ? true : false);
            }
            if ((int) $regra['acao_validacao'] && (int) $regra['acao_validacao_users_id'] > 0) {
                $passo('Validação', fn() => self::validacao($id, (int) $regra['acao_validacao_users_id'], $txt($regra['acao_validacao_texto'] ?: '<p>Validação solicitada pela regra {regra}.</p>')));
            }
            if ((int) $regra['acao_status'] && !$finaliza) {
                $passo('Status', fn() => self::atualizar($ticket, ['status' => (int) $regra['acao_status_valor']]));
            }
            if ((int) $regra['acao_solucionar'] && (int) $regra['acao_solucionar_users_id'] > 0) {
                $passo('Solucionar', function () use ($ticket, $regra, $id, $txt) {
                    if ((int) $regra['acao_solucionar_itilcategories_id'] > 0) {
                        self::atualizar($ticket, ['itilcategories_id' => (int) $regra['acao_solucionar_itilcategories_id']]);
                    }
                    self::atender($ticket, (int) $regra['acao_solucionar_users_id'], 0, false);
                    $s = new ITILSolution();
                    return (bool) $s->add([
                        'itemtype' => 'Ticket',
                        'items_id' => $id,
                        'content'  => self::temTexto($regra['acao_solucionar_texto']) ? $txt($regra['acao_solucionar_texto']) : '<p>Solução aplicada automaticamente pela regra ' . htmlspecialchars((string) $regra['name']) . '.</p>',
                    ]);
                });
            } elseif ((int) $regra['acao_pendente'] && (int) $regra['acao_pendente_users_id'] > 0) {
                $passo('Pendente', fn() => self::pendente($ticket, (int) $regra['acao_pendente_users_id'], (int) $regra['acao_pendente_pendingreasons_id'], $txt($regra['acao_pendente_followup_texto'])));
            }
            if ((int) $regra['acao_excluir']) {
                $purgar = (bool) (int) $regra['acao_excluir_purgar'];
                $passo($purgar ? 'Excluir definitivamente' : 'Mover para a lixeira', fn() => (bool) $ticket->delete(['id' => $id, '_disablenotif' => true], $purgar));
                $parar = true;
            }
        } finally {
            self::$executando--;
        }
        if ($acoes) {
            PluginAcoesautomaticasRegra::contar((int) $regra['id']);
        }
        return ['acoes' => $acoes, 'erros' => $erros, 'parar' => $parar];
    }

    private static function atualizar(Ticket $ticket, array $campos): bool
    {
        return (bool) $ticket->update(['id' => (int) $ticket->getID(), '_disablenotif' => true] + $campos);
    }

    private static function usuario(int $ticketId, int $userId, int $papel): bool
    {
        if (countElementsInTable('glpi_tickets_users', ['tickets_id' => $ticketId, 'users_id' => $userId, 'type' => $papel]) > 0) {
            return true;
        }
        return (bool) (new Ticket_User())->add(['tickets_id' => $ticketId, 'users_id' => $userId, 'type' => $papel]);
    }

    private static function grupo(int $ticketId, int $groupId, int $papel): bool
    {
        if (countElementsInTable('glpi_groups_tickets', ['tickets_id' => $ticketId, 'groups_id' => $groupId, 'type' => $papel]) > 0) {
            return true;
        }
        return (bool) (new Group_Ticket())->add(['tickets_id' => $ticketId, 'groups_id' => $groupId, 'type' => $papel]);
    }

    /** Atribui o técnico, opcionalmente troca o grupo observador pelo grupo observador do técnico, e põe em atendimento */
    private static function atender(Ticket $ticket, int $userId, int $categoria, bool $trocarObservador): bool
    {
        global $DB;
        $id = (int) $ticket->getID();
        self::usuario($id, $userId, CommonITILActor::ASSIGN);
        if ($trocarObservador) {
            $grupo = 0;
            foreach ($DB->request([
                'SELECT'     => ['gu.groups_id'],
                'FROM'       => 'glpi_groups_users AS gu',
                'INNER JOIN' => ['glpi_groups AS g' => ['ON' => ['gu' => 'groups_id', 'g' => 'id']]],
                'WHERE'      => ['gu.users_id' => $userId, 'g.is_watcher' => 1],
                'ORDER'      => 'g.name ASC',
                'LIMIT'      => 1,
            ]) as $r) {
                $grupo = (int) $r['groups_id'];
            }
            if ($grupo > 0) {
                foreach ($DB->request(['SELECT' => ['id', 'groups_id'], 'FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => $id, 'type' => CommonITILActor::OBSERVER]]) as $r) {
                    if ((int) $r['groups_id'] !== $grupo) {
                        (new Group_Ticket())->delete(['id' => (int) $r['id']], true);
                    }
                }
                self::grupo($id, $grupo, CommonITILActor::OBSERVER);
            }
        }
        $ticket->getFromDB($id);
        $campos = [];
        if ($categoria > 0) {
            $campos['itilcategories_id'] = $categoria;
        }
        if (in_array((int) $ticket->fields['status'], [Ticket::INCOMING, Ticket::WAITING], true)) {
            $campos['status'] = Ticket::ASSIGNED;
        }
        return $campos ? self::atualizar($ticket, $campos) : true;
    }

    private static function acompanhamento(int $ticketId, string $html, bool $privado): bool
    {
        return (bool) (new ITILFollowup())->add(['itemtype' => 'Ticket', 'items_id' => $ticketId, 'content' => $html, 'is_private' => (int) $privado]);
    }

    /** Pedido de validação (GLPI 11/12: o aprovador vai em itemtype_target/items_id_target) */
    private static function validacao(int $ticketId, int $userId, string $html): bool
    {
        return (bool) (new TicketValidation())->add([
            'tickets_id'         => $ticketId,
            'itemtype_target'    => 'User',
            'items_id_target'    => $userId,
            'comment_submission' => $html,
        ]);
    }

    /** Pendente pelo caminho nativo: acompanhamento com "pendente" e motivo (o GLPI muda o status) */
    private static function pendente(Ticket $ticket, int $userId, int $motivo, string $html): bool
    {
        $id = (int) $ticket->getID();
        self::atender($ticket, $userId, 0, false);
        $texto = self::temTexto($html) ? $html : '<p>Chamado colocado em pendente automaticamente.</p>';
        $ok = (bool) (new ITILFollowup())->add([
            'itemtype'          => 'Ticket',
            'items_id'          => $id,
            'content'           => $texto,
            'is_private'        => 0,
            'pending'           => 1,
            'pendingreasons_id' => $motivo,
        ]);
        $ticket->getFromDB($id);
        if ((int) $ticket->fields['status'] !== Ticket::WAITING) {
            $ok = self::atualizar($ticket, ['status' => Ticket::WAITING]) && $ok;
        }
        return $ok;
    }

    // =====================================================================
    // Duplicados
    // =====================================================================

    /** Chamado novo igual (título e descrição) a outro em andamento: vira filho e é solucionado */
    public static function duplicados(Ticket $ticket): bool
    {
        global $DB;
        $C = PluginAcoesautomaticasConfig::class;
        if ((string) $C::getConfig('duplicados_ativo') !== '1') {
            return false;
        }
        $usuario = (int) $C::getConfig('duplicados_users_id');
        if ($usuario <= 0) {
            return false;
        }
        $id = (int) $ticket->getID();
        $titulo = self::normalizar($ticket->fields['name'] ?? '');
        $descricao = self::normalizar($ticket->fields['content'] ?? '');
        if ($titulo === '' || $descricao === '' || in_array((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true)) {
            return false;
        }
        $where = [
            'id'         => ['<>', $id],
            'is_deleted' => 0,
            'status'     => [Ticket::INCOMING, Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING],
            'name'       => $ticket->fields['name'],
        ];
        if ((string) $C::getConfig('duplicados_mesma_entidade') === '1') {
            $where['entities_id'] = (int) $ticket->fields['entities_id'];
        }
        $dias = $C::inteiro('duplicados_dias', 0, 3650);
        if ($dias > 0) {
            $where['date'] = ['>=', date('Y-m-d H:i:s', strtotime('-' . $dias . ' days'))];
        }
        $pai = 0;
        foreach ($DB->request(['SELECT' => ['id', 'name', 'content'], 'FROM' => 'glpi_tickets', 'WHERE' => $where, 'ORDER' => 'id ASC', 'LIMIT' => 50]) as $c) {
            if (self::normalizar($c['name']) === $titulo && self::normalizar($c['content']) === $descricao) {
                $pai = (int) $c['id'];
                break;
            }
        }
        if ($pai <= 0) {
            return false;
        }
        $paiTicket = new Ticket();
        $paiTicket->getFromDB($pai);
        $regra = ['id' => 0, 'name' => 'Solucionar duplicados'];
        $acoes = [];
        $erros = [];
        self::$executando++;
        try {
            (new Ticket_Ticket())->add(['tickets_id_1' => $id, 'tickets_id_2' => $pai, 'link' => Ticket_Ticket::SON_OF]);
            $acoes[] = 'Vinculado como filho do #' . $pai;
            $categoria = (int) $C::getConfig('duplicados_itilcategories_id');
            if ($categoria > 0) {
                self::atualizar($ticket, ['itilcategories_id' => $categoria]);
                $acoes[] = 'Categorizar';
            }
            self::atender($ticket, $usuario, 0, false);
            $acoes[] = 'Atender';
            if ((string) $C::getConfig('duplicados_avisar_pai') === '1') {
                self::acompanhamento($pai, '<p><strong>Um chamado duplicado foi vinculado a este e solucionado automaticamente:</strong> '
                    . self::substituir('{link}', $ticket, $regra) . ' (#' . $id . ' — ' . htmlspecialchars((string) $ticket->fields['name']) . ').</p>', false);
                $acoes[] = 'Aviso no chamado original';
            }
            $texto = (string) $C::getConfig('duplicados_texto_solucao');
            $s = new ITILSolution();
            $s->add([
                'itemtype' => 'Ticket',
                'items_id' => $id,
                'content'  => self::temTexto($texto) ? self::substituir($texto, $paiTicket, $regra) : '<p>Chamado duplicado de ' . self::substituir('{link}', $paiTicket, $regra) . '.</p>',
            ]);
            $acoes[] = 'Solucionar';
        } catch (\Throwable $e) {
            $erros[] = $e->getMessage();
        } finally {
            self::$executando--;
        }
        $ticket->getFromDB($id);
        PluginAcoesautomaticasLog::registrar('duplicado', $regra, $ticket, 'Duplicado do #' . $pai, $acoes, $erros);
        return true;
    }

    // =====================================================================
    // Teste e execução manual
    // =====================================================================

    /** Teste da regra num chamado, sem executar nada */
    public static function simular(array $regra, int $ticketId): array
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return ['ok' => false, 'mensagem' => 'Chamado #' . $ticketId . ' não encontrado.'];
        }
        if (!$ticket->canViewItem()) {
            return ['ok' => false, 'mensagem' => 'Você não pode ver o chamado #' . $ticketId . '.'];
        }
        $v = self::verificar($regra, $ticket, true);
        $itens = [['Regra ativa', (bool) (int) $regra['is_active'], (int) $regra['is_active'] ? 'sim' : 'a regra está desligada']];
        $itens[] = ['Janela de execução', self::naJanela($regra), PluginAcoesautomaticasRegra::textoJanela($regra)];
        if ((int) $ticket->fields['is_deleted']) {
            $itens[] = ['Chamado fora da lixeira', false, 'o chamado está na lixeira'];
        }
        $itens = array_merge($itens, $v['itens']);
        if (!$v['itens']) {
            $itens[] = ['Condições', true, 'a regra não tem condições: vale para todos os chamados'];
        }
        $passa = !in_array(false, array_column($itens, 1), true);
        return [
            'ok'       => true,
            'passa'    => $passa,
            'ticket'   => '#' . $ticketId . ' — ' . $ticket->fields['name'],
            'url'      => Ticket::getFormURLWithID($ticketId),
            'itens'    => array_map(fn($i) => ['rotulo' => $i[0], 'ok' => $i[1], 'detalhe' => $i[2]], $itens),
            'acoes'    => self::descrever($regra),
            'eventos'  => PluginAcoesautomaticasRegra::textoQuando($regra),
        ];
    }

    /** Filtro em SQL das condições estruturais (o texto é conferido em PHP) */
    private static function criterios(array $regra): array
    {
        $w = ['t.is_deleted' => 0];
        $ids = fn(string $c) => PluginAcoesautomaticasConfig::ids($regra[$c] ?? '');
        foreach (['cond_tipos' => 't.type', 'cond_status' => 't.status', 'cond_prioridades' => 't.priority', 'cond_urgencias' => 't.urgency', 'cond_categorias' => 't.itilcategories_id', 'cond_origens' => 't.requesttypes_id'] as $c => $col) {
            if ($ids($c)) {
                $w[$col] = $ids($c);
            }
        }
        $ent = self::entidadesDaRegra($regra);
        if ($ent) {
            $w['t.entities_id'] = $ent;
        }
        $sub = fn(string $tabela, array $onde) => new QuerySubQuery(['SELECT' => 'tickets_id', 'FROM' => $tabela, 'WHERE' => $onde]);
        if ($ids('cond_requerentes')) {
            $w[] = ['t.id' => $sub('glpi_tickets_users', ['type' => CommonITILActor::REQUESTER, 'users_id' => $ids('cond_requerentes')])];
        }
        if ($ids('cond_grupos_observadores')) {
            $w[] = ['t.id' => $sub('glpi_groups_tickets', ['type' => CommonITILActor::OBSERVER, 'groups_id' => $ids('cond_grupos_observadores')])];
        }
        if ($ids('cond_grupos_atribuidos')) {
            $w[] = ['t.id' => $sub('glpi_groups_tickets', ['type' => CommonITILActor::ASSIGN, 'groups_id' => $ids('cond_grupos_atribuidos')])];
        }
        if ((int) $regra['cond_sem_tecnico']) {
            $w[] = ['NOT' => ['t.id' => $sub('glpi_tickets_users', ['type' => CommonITILActor::ASSIGN])]];
            $w[] = ['NOT' => ['t.id' => $sub('glpi_groups_tickets', ['type' => CommonITILActor::ASSIGN])]];
        }
        return $w;
    }

    private static function candidatos(array $regra, int $apos, int $limite): array
    {
        global $DB;
        $w = self::criterios($regra);
        $w[] = ['t.id' => ['>', $apos]];
        return array_map(fn($r) => (int) $r['id'], iterator_to_array($DB->request(['SELECT' => ['t.id'], 'FROM' => 'glpi_tickets AS t', 'WHERE' => $w, 'ORDER' => 't.id ASC', 'LIMIT' => $limite]), false));
    }

    /** Prévia da execução manual: quantos chamados batem (até 3.000 avaliados) e uma amostra */
    public static function previa(array $regra): array
    {
        global $DB;
        $total = 0;
        foreach ($DB->request(['COUNT' => 'n', 'FROM' => 'glpi_tickets AS t', 'WHERE' => self::criterios($regra)]) as $r) {
            $total = (int) $r['n'];
        }
        $avaliados = 0;
        $batem = 0;
        $amostra = [];
        $apos = 0;
        $ticket = new Ticket();
        while ($avaliados < 3000) {
            $lote = self::candidatos($regra, $apos, 500);
            if (!$lote) {
                break;
            }
            foreach ($lote as $id) {
                $apos = $id;
                $avaliados++;
                if ($ticket->getFromDB($id) && self::verificar($regra, $ticket)['ok']) {
                    $batem++;
                    if (count($amostra) < 15) {
                        $amostra[] = ['id' => $id, 'titulo' => (string) $ticket->fields['name'], 'status' => Ticket::getStatus((int) $ticket->fields['status']), 'url' => Ticket::getFormURLWithID($id)];
                    }
                }
            }
        }
        return ['candidatos' => $total, 'avaliados' => $avaliados, 'batem' => $batem, 'parcial' => $avaliados < $total, 'amostra' => $amostra];
    }

    /** Um lote da execução manual: avalia candidatos depois de $apos e executa até $max chamados */
    public static function lote(array $regra, int $apos, int $max = 20): array
    {
        $executados = 0;
        $avaliados = 0;
        $erros = 0;
        $ticket = new Ticket();
        $inicio = microtime(true);
        while ($executados < $max && microtime(true) - $inicio < 20) {
            $lote = self::candidatos($regra, $apos, 100);
            if (!$lote) {
                return ['apos' => $apos, 'executados' => $executados, 'avaliados' => $avaliados, 'erros' => $erros, 'fim' => true];
            }
            foreach ($lote as $id) {
                $apos = $id;
                $avaliados++;
                if (!$ticket->getFromDB($id) || !self::verificar($regra, $ticket)['ok']) {
                    continue;
                }
                $r = self::executar($regra, $ticket);
                $ticket->getFromDB($id);
                PluginAcoesautomaticasLog::registrar('manual', $regra, $ticket, 'Execução manual', $r['acoes'], $r['erros']);
                $executados++;
                $erros += count($r['erros']) > 0 ? 1 : 0;
                if ($executados >= $max) {
                    break;
                }
            }
        }
        return ['apos' => $apos, 'executados' => $executados, 'avaliados' => $avaliados, 'erros' => $erros, 'fim' => false];
    }
}
