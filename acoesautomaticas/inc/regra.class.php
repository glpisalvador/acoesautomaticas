<?php

/**
 * Plugin Ações Automáticas - a regra (item nativo do GLPI, com histórico).
 * Formulário em seções: regra, quando executar, condições e ações. Abas: chamados filhos,
 * testar e executar, execuções e histórico. Também duplica, exporta e importa regras (JSON).
 */
class PluginAcoesautomaticasRegra extends CommonDBTM
{
    // $rightname e $dohistory não são redeclaradas: são tipadas no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_acoesautomaticas_regras';

    public const QUANDO = [0 => 'Na criação', 1 => 'Na atualização', 2 => 'Na criação e na atualização'];
    public const JANELAS = [0 => 'Sempre', 1 => 'Só num horário do dia', 2 => 'Só num período de datas'];

    /** Condições em lista (JSON) */
    public const LISTAS = ['cond_tipos', 'cond_status', 'cond_entidades', 'cond_requerentes', 'cond_grupos_observadores', 'cond_grupos_atribuidos', 'cond_categorias', 'cond_prioridades', 'cond_urgencias', 'cond_origens'];

    /** Ligar/desligar (switches) */
    public const CHAVES = [
        'is_active', 'parar_apos_executar', 'cond_subentidades', 'cond_sem_tecnico',
        'acao_atender', 'acao_atender_trocar_observador', 'acao_categorizar', 'acao_grupo_tecnico', 'acao_observador', 'acao_grupo_observador',
        'acao_prioridade', 'acao_urgencia', 'acao_followup', 'acao_followup_privado', 'acao_status', 'acao_validacao', 'acao_tickets_filhos',
        'acao_pendente', 'acao_solucionar', 'acao_excluir', 'acao_excluir_purgar',
    ];

    public const TEXTOS_RICOS = ['comment', 'acao_followup_texto', 'acao_validacao_texto', 'acao_pendente_followup_texto', 'acao_solucionar_texto'];

    public function __construct()
    {
        parent::__construct();
        $this->dohistory = true;
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Regras automáticas' : 'Regra automática';
    }

    public static function getIcon(): string
    {
        return 'ti ti-robot';
    }

    private static function pode(int $direito): bool
    {
        return (bool) Session::haveRight(PluginAcoesautomaticasConfig::DIREITO, $direito);
    }

    public static function canView(): bool
    {
        return self::pode(READ);
    }

    public static function canCreate(): bool
    {
        return self::pode(CREATE);
    }

    public static function canUpdate(): bool
    {
        return self::pode(UPDATE);
    }

    public static function canDelete(): bool
    {
        return self::pode(DELETE);
    }

    public static function canPurge(): bool
    {
        return self::pode(PURGE);
    }

    public function getRights($interface = 'central')
    {
        return [READ => __('Read'), CREATE => __('Create'), UPDATE => __('Update'), PURGE => __('Delete permanently')];
    }

    // =====================================================================
    // Dados
    // =====================================================================

    public static function todas(): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::TABELA, 'ORDER' => ['ordem ASC', 'id ASC']]), false);
    }

    private static ?array $ativasCache = null;

    public static function ativas(): array
    {
        global $DB;
        return self::$ativasCache ??= iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => ['is_active' => 1], 'ORDER' => ['ordem ASC', 'id ASC']]), false);
    }

    public static function limparCache(): void
    {
        self::$ativasCache = null;
    }

    public static function contar(int $id): void
    {
        global $DB;
        if ($id > 0) {
            $DB->update(self::TABELA, ['contador_execucoes' => new \Glpi\DBAL\QueryExpression('`contador_execucoes` + 1'), 'ultima_execucao' => date('Y-m-d H:i:s')], ['id' => $id]);
        }
    }

    public static function proximaOrdem(): int
    {
        global $DB;
        $r = $DB->request(['SELECT' => [new \Glpi\DBAL\QueryExpression('MAX(`ordem`) AS m')], 'FROM' => self::TABELA])->current();
        return (int) ($r['m'] ?? 0) + 1;
    }

    // =====================================================================
    // Textos de resumo
    // =====================================================================

    public static function textoQuando(array $r): string
    {
        $t = self::QUANDO[(int) $r['executar_em']] ?? '';
        if ((int) $r['executar_em'] > 0) {
            $ev = PluginAcoesautomaticasMotor::eventos($r['tipos_atualizacao']);
            $t .= $ev ? ' (' . implode(', ', array_map(fn($e) => mb_strtolower(PluginAcoesautomaticasMotor::EVENTOS[$e]), $ev)) . ')' : ' (qualquer alteração)';
        }
        return $t;
    }

    public static function textoJanela(array $r): string
    {
        $data = fn($v) => $v ? Html::convDateTime((string) $v) : '…';
        return match ((int) $r['range_tipo']) {
            1       => 'das ' . substr((string) $r['range_hora_inicio'], 0, 5) . ' às ' . substr((string) $r['range_hora_fim'], 0, 5),
            2       => 'de ' . $data($r['range_data_inicio']) . ' até ' . $data($r['range_data_fim']),
            default => 'sempre',
        };
    }

    /** Condições preenchidas, em texto curto */
    public static function textoCondicoes(array $r): array
    {
        $ids = fn(string $c, bool $zero = false) => PluginAcoesautomaticasConfig::ids($r[$c] ?? '', $zero);
        $nomes = fn(string $tabela, array $l) => implode(', ', array_map(fn($i) => Dropdown::getDropdownName($tabela, $i), $l));
        $l = [];
        if ($ids('cond_tipos')) {
            $l[] = 'Tipo: ' . implode(', ', array_map(fn($v) => Ticket::getTicketTypeName($v), $ids('cond_tipos')));
        }
        if ($ids('cond_status')) {
            $l[] = 'Status: ' . implode(', ', array_map(fn($v) => Ticket::getStatus($v), $ids('cond_status')));
        }
        if ($ids('cond_entidades', true)) {
            $l[] = 'Entidade: ' . $nomes('glpi_entities', $ids('cond_entidades', true)) . ((int) $r['cond_subentidades'] ? ' (e filhas)' : '');
        }
        if ($ids('cond_categorias')) {
            $l[] = 'Categoria: ' . $nomes('glpi_itilcategories', $ids('cond_categorias'));
        }
        if ($ids('cond_prioridades')) {
            $l[] = 'Prioridade: ' . implode(', ', array_map(fn($v) => CommonITILObject::getPriorityName($v), $ids('cond_prioridades')));
        }
        if ($ids('cond_urgencias')) {
            $l[] = 'Urgência: ' . implode(', ', array_map(fn($v) => CommonITILObject::getUrgencyName($v), $ids('cond_urgencias')));
        }
        if ($ids('cond_origens')) {
            $l[] = 'Origem: ' . $nomes('glpi_requesttypes', $ids('cond_origens'));
        }
        if ($ids('cond_requerentes')) {
            $l[] = 'Requerente: ' . implode(', ', array_map('getUserName', $ids('cond_requerentes')));
        }
        if ($ids('cond_grupos_observadores')) {
            $l[] = 'Grupo observador: ' . $nomes('glpi_groups', $ids('cond_grupos_observadores'));
        }
        if ($ids('cond_grupos_atribuidos')) {
            $l[] = 'Grupo atribuído: ' . $nomes('glpi_groups', $ids('cond_grupos_atribuidos'));
        }
        if ((int) $r['cond_sem_tecnico']) {
            $l[] = 'Sem atribuição';
        }
        foreach (['titulo_contem' => 'Título contém', 'titulo_nao_contem' => 'Título não contém', 'descricao_contem' => 'Descrição contém', 'followup_contem' => 'Acompanhamento contém'] as $c => $rotulo) {
            if (trim((string) ($r[$c] ?? '')) !== '') {
                $l[] = $rotulo . ': "' . trim((string) $r[$c]) . '"';
            }
        }
        return $l;
    }

    // =====================================================================
    // Gravação
    // =====================================================================

    private function normalizar(array $input, bool $novo)
    {
        $erros = [];
        if (isset($input['name'])) {
            $input['name'] = mb_substr(trim(strip_tags((string) $input['name'])), 0, 255);
            if ($input['name'] === '') {
                $erros[] = 'Informe o nome da regra.';
            }
        } elseif ($novo) {
            $erros[] = 'Informe o nome da regra.';
        }
        // Condições em lista: o formulário marca "_condicoes" para que lista vazia limpe o filtro
        foreach (self::LISTAS as $c) {
            if (array_key_exists($c, $input) || !empty($input['_condicoes'])) {
                $input[$c] = json_encode(PluginAcoesautomaticasConfig::ids($input[$c] ?? [], $c === 'cond_entidades' || $c === 'cond_categorias' || $c === 'cond_origens'));
            }
        }
        if (array_key_exists('tipos_atualizacao', $input) || !empty($input['_condicoes'])) {
            $input['tipos_atualizacao'] = json_encode(PluginAcoesautomaticasMotor::eventos(array_filter((array) ($input['tipos_atualizacao'] ?? []))));
        }
        foreach (['titulo_contem', 'titulo_nao_contem'] as $c) {
            if (isset($input[$c])) {
                $input[$c] = mb_substr(trim(strip_tags((string) $input[$c])), 0, 255);
            }
        }
        foreach (['descricao_contem', 'followup_contem'] as $c) {
            if (isset($input[$c])) {
                $input[$c] = mb_substr(trim(strip_tags((string) $input[$c])), 0, 5000);
            }
        }
        foreach (self::CHAVES as $c) {
            if (isset($input[$c])) {
                $input[$c] = empty($input[$c]) ? 0 : 1;
            }
        }
        if (isset($input['executar_em'])) {
            $input['executar_em'] = isset(self::QUANDO[(int) $input['executar_em']]) ? (int) $input['executar_em'] : 0;
        }
        if (isset($input['range_tipo'])) {
            $tipo = isset(self::JANELAS[(int) $input['range_tipo']]) ? (int) $input['range_tipo'] : 0;
            $input['range_tipo'] = $tipo;
            foreach (['range_hora_inicio', 'range_hora_fim'] as $c) {
                $v = trim((string) ($input[$c] ?? ''));
                $input[$c] = preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $v) ? substr($v, 0, 5) . ':00' : 'NULL';
            }
            foreach (['range_data_inicio', 'range_data_fim'] as $c) {
                $v = trim((string) ($input[$c] ?? ''));
                $input[$c] = preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v) ? $v : 'NULL';
            }
            if ($tipo === 1 && ($input['range_hora_inicio'] === 'NULL' || $input['range_hora_fim'] === 'NULL')) {
                $erros[] = 'Informe o horário de início e de fim da janela.';
            }
            if ($tipo === 2 && $input['range_data_inicio'] === 'NULL' && $input['range_data_fim'] === 'NULL') {
                $erros[] = 'Informe ao menos uma data da janela.';
            }
            if ($tipo === 2 && $input['range_data_inicio'] !== 'NULL' && $input['range_data_fim'] !== 'NULL' && strtotime($input['range_data_fim']) <= strtotime($input['range_data_inicio'])) {
                $erros[] = 'O fim da janela deve ser depois do início.';
            }
        }
        foreach (['acao_prioridade_valor' => [1, 6], 'acao_urgencia_valor' => [1, 5]] as $c => [$min, $max]) {
            if (isset($input[$c])) {
                $input[$c] = max($min, min($max, (int) $input[$c]));
            }
        }
        // Ações que precisam de um usuário
        $exige = [
            'acao_atender'    => ['acao_atender_users_id', 'o técnico de "Atender"'],
            'acao_solucionar' => ['acao_solucionar_users_id', 'o técnico de "Solucionar"'],
            'acao_pendente'   => ['acao_pendente_users_id', 'o técnico de "Pendente"'],
            'acao_validacao'  => ['acao_validacao_users_id', 'o aprovador de "Pedir validação"'],
            'acao_observador' => ['acao_observador_users_id', 'o usuário de "Adicionar observador"'],
            'acao_grupo_tecnico' => ['acao_grupo_tecnico_groups_id', 'o grupo de "Grupo técnico"'],
            'acao_grupo_observador' => ['acao_grupo_observador_groups_id', 'o grupo de "Grupo observador"'],
            'acao_categorizar' => ['acao_categorizar_itilcategories_id', 'a categoria de "Categorizar"'],
        ];
        foreach ($exige as $acao => [$campo, $texto]) {
            $ligada = (int) ($input[$acao] ?? ($this->fields[$acao] ?? 0));
            $valor = (int) ($input[$campo] ?? ($this->fields[$campo] ?? 0));
            if ($ligada && $valor <= 0) {
                $erros[] = 'Escolha ' . $texto . '.';
            }
        }
        if ((int) ($input['acao_solucionar'] ?? 0) && (int) ($input['acao_pendente'] ?? 0)) {
            $erros[] = '"Solucionar" e "Pendente" não podem ficar ligadas juntas.';
        }
        unset($input['_condicoes'], $input['contador_execucoes'], $input['ultima_execucao']);
        if ($erros) {
            foreach ($erros as $e) {
                Session::addMessageAfterRedirect($e, false, ERROR);
            }
            return false;
        }
        return $input;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->normalizar($input, true);
        if ($input === false) {
            return false;
        }
        $input['ordem'] = self::proximaOrdem();
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        return $this->normalizar($input, false);
    }

    public function post_addItem()
    {
        self::limparCache();
    }

    public function post_updateItem($history = true)
    {
        self::limparCache();
    }

    public function cleanDBonPurge()
    {
        global $DB;
        $DB->delete(PluginAcoesautomaticasFilho::TABELA, ['regras_id' => (int) $this->getID()]);
        self::limparCache();
        PluginAcoesautomaticasLog::registrar('alteracao', $this->fields, null, 'Regra excluída', [], []);
    }

    // =====================================================================
    // Duplicar, exportar e importar
    // =====================================================================

    private const NAO_COPIAR = ['id', 'date_creation', 'date_mod', 'contador_execucoes', 'ultima_execucao', 'ordem', 'cond_entidade_antiga', 'tipo_ticket', 'status_ticket', 'users_id_requerente', 'groups_id_observador'];

    public static function exportar(int $id): ?array
    {
        $r = new self();
        if (!$r->getFromDB($id)) {
            return null;
        }
        $dados = array_diff_key($r->fields, array_flip(self::NAO_COPIAR));
        $filhos = array_map(fn($f) => array_diff_key($f, array_flip(['id', 'regras_id', 'date_creation', 'date_mod'])), PluginAcoesautomaticasFilho::daRegra($id));
        return ['formato' => 'acoesautomaticas-regra', 'versao' => PLUGIN_ACOESAUTOMATICAS_VERSION, 'exportada' => date('Y-m-d H:i:s'), 'regra' => $dados, 'filhos' => $filhos];
    }

    /** Cria uma regra (desligada) a partir de um export; retorna o id ou a mensagem de erro */
    public static function importar(array $dados, string $sufixo = ''): int|string
    {
        global $DB;
        if (($dados['formato'] ?? '') !== 'acoesautomaticas-regra' || !is_array($dados['regra'] ?? null)) {
            return 'O arquivo não é uma regra exportada pelo plugin.';
        }
        $colunas = $DB->listFields(self::TABELA);
        $campos = array_intersect_key($dados['regra'], $colunas);
        $campos = array_diff_key($campos, array_flip(self::NAO_COPIAR));
        $campos['name'] = mb_substr(trim((string) ($campos['name'] ?? 'Regra importada')) . $sufixo, 0, 255);
        $campos['is_active'] = 0;
        $campos['ordem'] = self::proximaOrdem();
        $campos['contador_execucoes'] = 0;
        foreach ($campos as $k => $v) {
            if (is_array($v)) {
                $campos[$k] = json_encode($v);
            }
        }
        $r = new self();
        $id = (int) $r->add($campos + ['_importacao' => 1]);
        if ($id <= 0) {
            return 'Não foi possível criar a regra (verifique as mensagens).';
        }
        $colunasF = $DB->listFields(PluginAcoesautomaticasFilho::TABELA);
        foreach ((array) ($dados['filhos'] ?? []) as $i => $f) {
            if (is_array($f)) {
                $DB->insert(PluginAcoesautomaticasFilho::TABELA, array_intersect_key($f, $colunasF) + ['regras_id' => $id, 'ordem' => $i + 1]);
            }
        }
        return $id;
    }

    // =====================================================================
    // Abas
    // =====================================================================

    public function defineTabs($options = [])
    {
        $abas = [];
        $this->addDefaultFormTab($abas);
        $this->addStandardTab('PluginAcoesautomaticasFilho', $abas, $options);
        $this->addStandardTab(self::class, $abas, $options);
        $this->addStandardTab('Log', $abas, $options);
        return $abas;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof self || $item->isNewItem()) {
            return '';
        }
        $n = !empty($_SESSION['glpishow_count_on_tabs']) ? countElementsInTable(PluginAcoesautomaticasLog::TABELA, ['regras_id' => (int) $item->getID()]) : 0;
        return [
            1 => self::createTabEntry('Testar e executar', 0, null, 'ti ti-player-play'),
            2 => self::createTabEntry('Execuções', $n, null, 'ti ti-history'),
        ];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof self) {
            if ((int) $tabnum === 1) {
                $item->mostrarTeste();
            } else {
                PluginAcoesautomaticasLog::mostrarDaRegra((int) $item->getID());
            }
        }
        return true;
    }

    public function rawSearchOptions()
    {
        $t = self::TABELA;
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool'],
            ['id' => 4, 'table' => $t, 'field' => 'contador_execucoes', 'name' => 'Execuções', 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 5, 'table' => $t, 'field' => 'ultima_execucao', 'name' => 'Última execução', 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 6, 'table' => $t, 'field' => 'ordem', 'name' => 'Ordem', 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 16, 'table' => $t, 'field' => 'comment', 'name' => __('Comments'), 'datatype' => 'text', 'htmltext' => true],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 121, 'table' => $t, 'field' => 'date_creation', 'name' => __('Creation date'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    // =====================================================================
    // Formulário
    // =====================================================================

    private static function chave(string $nome, bool $ligado, string $rotulo, string $extra = ''): string
    {
        $id = 'aa-' . $nome . '-' . mt_rand();
        return '<input type="hidden" name="' . $nome . '" value="0"><div class="form-check form-switch acoesautomaticas-switch"' . $extra . '>'
            . '<input class="form-check-input" type="checkbox" role="switch" id="' . $id . '" name="' . $nome . '" value="1"' . ($ligado ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . $id . '">' . $rotulo . '</label></div>';
    }

    private static function marcar(string $nome, bool $ligado, string $rotulo): string
    {
        return '<input type="hidden" name="' . $nome . '" value="0"><label class="acoesautomaticas-opcao"><input type="checkbox" class="acoesautomaticas-check" name="' . $nome . '" value="1"' . ($ligado ? ' checked' : '') . '> ' . $rotulo . '</label>';
    }

    private static function rico(string $nome, ?string $valor, int $linhas = 4): string
    {
        return (string) Html::textarea(['name' => $nome, 'value' => (string) $valor, 'enable_richtext' => true, 'cols' => 100, 'rows' => $linhas, 'display' => false]);
    }

    private static function selecao(string $nome, array $opcoes, $atual): string
    {
        return (string) Dropdown::showFromArray($nome, $opcoes, ['value' => $atual, 'display' => false]);
    }

    public function showForm($ID, array $options = [])
    {
        $C = PluginAcoesautomaticasConfig::class;
        $e = [$C, 'e'];
        $this->initForm($ID, $options);
        $novo = $this->isNewItem();
        $f = $this->fields;
        if ($novo) {
            $f['is_active'] = 1;
            $f['cond_subentidades'] = 1;
            $f['acao_atender_trocar_observador'] = 1;
            $f['acao_prioridade_valor'] = 3;
            $f['acao_urgencia_valor'] = 3;
            $f['acao_status_valor'] = Ticket::ASSIGNED;
        }
        $ids = fn(string $c, bool $zero = false) => $C::ids($f[$c] ?? '', $zero);
        $v = fn(string $c) => (int) ($f[$c] ?? 0);

        echo $C::assets();
        $this->showFormHeader($options);
        echo '<input type="hidden" name="_condicoes" value="1">';
        $secao = fn(string $icone, string $titulo, string $ajuda = '') => '<tr class="tab_bg_2"><th colspan="4" class="acoesautomaticas-secao"><i class="' . $icone . '"></i> ' . $e($titulo)
            . ($ajuda !== '' ? '<span class="acoesautomaticas-secao-ajuda">' . $ajuda . '</span>' : '') . '</th></tr>';
        $linha = fn(string $r1, string $c1, string $r2 = '', string $c2 = '') => '<tr class="tab_bg_1"><td class="acoesautomaticas-rotulo">' . $r1 . '</td><td>' . $c1 . '</td><td class="acoesautomaticas-rotulo">' . $r2 . '</td><td>' . $c2 . '</td></tr>';
        $larga = fn(string $r, string $c, string $extra = '') => '<tr class="tab_bg_1"' . $extra . '><td class="acoesautomaticas-rotulo">' . $r . '</td><td colspan="3">' . $c . '</td></tr>';

        // ------------------------------------------------------------ regra
        if (!$novo) {
            echo '<tr class="tab_bg_1"><td colspan="4"><div class="acoesautomaticas-resumo">'
                . '<span><i class="ti ti-sort-ascending-numbers"></i> Ordem ' . (int) $f['ordem'] . '</span>'
                . '<span><i class="ti ti-player-play"></i> ' . number_format((int) $f['contador_execucoes'], 0, ',', '.') . ' execução(ões)</span>'
                . '<span><i class="ti ti-clock"></i> ' . (!empty($f['ultima_execucao']) ? 'última em ' . $e(Html::convDateTime((string) $f['ultima_execucao'])) : 'nunca executada') . '</span>'
                . '<a class="btn btn-sm btn-ghost-secondary ms-auto" href="' . $e($C::url('regra.form.php', ['exportar' => (int) $ID])) . '"><i class="ti ti-download"></i><span>Exportar</span></a>'
                . '</div></td></tr>';
        }
        echo $secao('ti ti-robot', 'Regra');
        echo $linha('<label for="aa-nome">Nome <span class="required">*</span></label>', '<input type="text" id="aa-nome" class="form-control" name="name" maxlength="255" required value="' . $e($f['name'] ?? '') . '">',
            'Situação', self::chave('is_active', (bool) $v('is_active'), 'Regra ligada'));
        echo $larga('Comentário', self::rico('comment', $f['comment'] ?? '', 3));

        // ------------------------------------------------------------ quando
        echo $secao('ti ti-bolt', 'Quando executar');
        echo $linha('Executar', self::selecao('executar_em', self::QUANDO, $v('executar_em')),
            'Depois desta regra', self::chave('parar_apos_executar', (bool) $v('parar_apos_executar'), 'Não executar as regras seguintes'));
        echo $larga('Eventos de atualização', $C::multiselect('tipos_atualizacao', PluginAcoesautomaticasMotor::EVENTOS, PluginAcoesautomaticasMotor::eventos($f['tipos_atualizacao'] ?? ''), 'Qualquer alteração')
            . '<small class="acoesautomaticas-dica">Vazio: a regra roda em qualquer alteração do chamado. O que as próprias regras fazem não dispara regras de novo.</small>',
            ' data-acoesautomaticas-eventos');
        echo $linha('Janela de execução', self::selecao('range_tipo', self::JANELAS, $v('range_tipo')), '', '');
        echo '<tr class="tab_bg_1" data-acoesautomaticas-janela="1"><td class="acoesautomaticas-rotulo">Horário</td><td colspan="3"><div class="acoesautomaticas-linha">'
            . '<span>das</span><input type="time" class="form-control form-control-sm acoesautomaticas-hora" name="range_hora_inicio" value="' . $e(substr((string) ($f['range_hora_inicio'] ?? ''), 0, 5)) . '">'
            . '<span>às</span><input type="time" class="form-control form-control-sm acoesautomaticas-hora" name="range_hora_fim" value="' . $e(substr((string) ($f['range_hora_fim'] ?? ''), 0, 5)) . '">'
            . '<small class="acoesautomaticas-dica">Pode atravessar a meia-noite (ex.: 18:00 às 08:00).</small></div></td></tr>';
        echo '<tr class="tab_bg_1" data-acoesautomaticas-janela="2"><td class="acoesautomaticas-rotulo">Período</td><td colspan="3"><div class="acoesautomaticas-linha">'
            . '<span>de</span>' . Html::showDateTimeField('range_data_inicio', ['value' => $f['range_data_inicio'] ?? '', 'display' => false, 'maybeempty' => true])
            . '<span>até</span>' . Html::showDateTimeField('range_data_fim', ['value' => $f['range_data_fim'] ?? '', 'display' => false, 'maybeempty' => true])
            . '<small class="acoesautomaticas-dica">Depois do fim a regra é desligada automaticamente.</small></div></td></tr>';

        // ------------------------------------------------------------ condições
        echo $secao('ti ti-filter', 'Condições', 'Campos vazios não restringem; todas as condições preenchidas precisam ser atendidas.');
        $prioridades = [];
        foreach ([6, 5, 4, 3, 2, 1] as $p) {
            $prioridades[$p] = CommonITILObject::getPriorityName($p);
        }
        $urgencias = [];
        foreach ([5, 4, 3, 2, 1] as $u) {
            $urgencias[$u] = CommonITILObject::getUrgencyName($u);
        }
        $origens = [];
        foreach ((new RequestType())->find([], ['name']) as $o) {
            $origens[(int) $o['id']] = (string) $o['name'];
        }
        echo $linha('Tipo', $C::multiselect('cond_tipos', Ticket::getTypes(), $ids('cond_tipos')), 'Status', $C::multiselect('cond_status', Ticket::getAllStatusArray(), $ids('cond_status')));
        echo $linha('Prioridade', $C::multiselect('cond_prioridades', $prioridades, $ids('cond_prioridades')), 'Urgência', $C::multiselect('cond_urgencias', $urgencias, $ids('cond_urgencias')));
        echo $linha('Categoria', (string) ITILCategory::dropdown(['name' => 'cond_categorias[]', 'multiple' => true, 'values' => $ids('cond_categorias'), 'display' => false, 'entity' => -1, 'width' => '100%']),
            'Origem', $C::multiselect('cond_origens', $origens, $ids('cond_origens')));
        echo $linha('Entidade', (string) Entity::dropdown(['name' => 'cond_entidades[]', 'multiple' => true, 'values' => $ids('cond_entidades', true), 'display' => false, 'entity' => $_SESSION['glpiactiveentities'] ?? [0], 'width' => '100%'])
            . self::marcar('cond_subentidades', (bool) $v('cond_subentidades'), 'incluir as entidades filhas'),
            'Requerente', (string) User::dropdown(['name' => 'cond_requerentes[]', 'multiple' => true, 'value' => $ids('cond_requerentes'), 'values' => $ids('cond_requerentes'), 'right' => 'all', 'display' => false, 'width' => '100%']));
        echo $linha('Grupo observador', (string) Group::dropdown(['name' => 'cond_grupos_observadores[]', 'multiple' => true, 'values' => $ids('cond_grupos_observadores'), 'display' => false, 'entity' => -1, 'width' => '100%']),
            'Grupo atribuído', (string) Group::dropdown(['name' => 'cond_grupos_atribuidos[]', 'multiple' => true, 'values' => $ids('cond_grupos_atribuidos'), 'display' => false, 'entity' => -1, 'width' => '100%']));
        echo $linha('Atribuição', self::chave('cond_sem_tecnico', (bool) $v('cond_sem_tecnico'), 'Só chamados sem técnico e sem grupo atribuídos'), '', '');
        $texto = fn(string $nome, string $ph) => '<input type="text" class="form-control" name="' . $nome . '" maxlength="255" value="' . $e($f[$nome] ?? '') . '" placeholder="' . $e($ph) . '">';
        echo $linha('Título contém', $texto('titulo_contem', 'ex.: impressora || financeiro'), 'Título não contém', $texto('titulo_nao_contem', 'ex.: teste || homologação'));
        echo $linha('Descrição contém', $texto('descricao_contem', 'trecho da descrição'), 'Algum acompanhamento contém', $texto('followup_contem', 'trecho de um acompanhamento'));
        echo '<tr class="tab_bg_1"><td></td><td colspan="3"><p class="acoesautomaticas-explicacao"><i class="ti ti-info-circle"></i><span>Nos textos, <code>||</code> separa trechos que precisam aparecer todos (em "não contém", basta um). Maiúsculas, acentos e formatação não importam.</span></p></td></tr>';

        // ------------------------------------------------------------ ações
        echo $secao('ti ti-checklist', 'Ações', 'Executadas nesta ordem quando as condições batem.');
        $acao = function (string $chave, string $icone, string $titulo, string $corpo, string $dica = '') use ($v, $e) {
            $ligada = (bool) $v($chave);
            return '<tr class="tab_bg_1 acoesautomaticas-acao' . ($ligada ? ' acoesautomaticas-acao-ligada' : '') . '" data-acoesautomaticas-acao="' . $chave . '">'
                . '<td class="acoesautomaticas-rotulo">' . self::chave($chave, $ligada, '<i class="' . $icone . '"></i> ' . $e($titulo)) . '</td>'
                . '<td colspan="3"><div class="acoesautomaticas-acao-corpo"' . ($ligada ? '' : ' hidden') . '>' . $corpo . ($dica !== '' ? '<small class="acoesautomaticas-dica">' . $dica . '</small>' : '') . '</div>'
                . '<span class="acoesautomaticas-acao-desligada"' . ($ligada ? ' hidden' : '') . '>desligada</span></td></tr>';
        };
        $campo = fn(string $rotulo, string $controle) => '<div class="acoesautomaticas-campo"><label>' . $e($rotulo) . '</label>' . $controle . '</div>';
        $usuario = fn(string $nome, string $right = 'all') => (string) User::dropdown(['name' => $nome, 'value' => $v($nome), 'right' => $right, 'display' => false, 'width' => '100%']);
        $grupo = fn(string $nome, array $cond = []) => (string) Group::dropdown(['name' => $nome, 'value' => $v($nome), 'display' => false, 'entity' => -1, 'condition' => $cond, 'width' => '100%']);
        $categoria = fn(string $nome) => (string) ITILCategory::dropdown(['name' => $nome, 'value' => $v($nome), 'display' => false, 'entity' => -1, 'width' => '100%']);
        $grade = fn(string ...$campos) => '<div class="acoesautomaticas-grade">' . implode('', $campos) . '</div>';

        echo $acao('acao_categorizar', 'ti ti-category', 'Categorizar', $grade($campo('Categoria', $categoria('acao_categorizar_itilcategories_id'))));
        echo $acao('acao_prioridade', 'ti ti-arrow-up-circle', 'Alterar prioridade', $grade($campo('Prioridade', self::selecao('acao_prioridade_valor', $prioridades, $v('acao_prioridade_valor')))));
        echo $acao('acao_urgencia', 'ti ti-alarm', 'Alterar urgência', $grade($campo('Urgência', self::selecao('acao_urgencia_valor', $urgencias, $v('acao_urgencia_valor')))));
        echo $acao('acao_grupo_tecnico', 'ti ti-users-group', 'Atribuir grupo técnico', $grade($campo('Grupo', $grupo('acao_grupo_tecnico_groups_id', ['is_assign' => 1]))));
        echo $acao('acao_atender', 'ti ti-user-check', 'Atender (atribuir técnico)', $grade($campo('Técnico', $usuario('acao_atender_users_id', 'own_ticket')), $campo('Categoria (opcional)', $categoria('acao_atender_itilcategories_id')))
            . self::marcar('acao_atender_trocar_observador', (bool) $v('acao_atender_trocar_observador'), 'Trocar o grupo observador pelo grupo observador do técnico'),
            'O chamado passa para "Em atendimento". Ignorada quando "Solucionar" ou "Pendente" estão ligadas (elas já atribuem).');
        echo $acao('acao_observador', 'ti ti-eye', 'Adicionar observador', $grade($campo('Usuário', $usuario('acao_observador_users_id'))));
        echo $acao('acao_grupo_observador', 'ti ti-users', 'Adicionar grupo observador', $grade($campo('Grupo', $grupo('acao_grupo_observador_groups_id', ['is_watcher' => 1]))));
        echo $acao('acao_followup', 'ti ti-message', 'Adicionar acompanhamento', self::rico('acao_followup_texto', $f['acao_followup_texto'] ?? '')
            . self::marcar('acao_followup_privado', (bool) $v('acao_followup_privado'), 'Acompanhamento privado'));
        echo $acao('acao_tickets_filhos', 'ti ti-git-fork', 'Criar chamados filhos', '<span class="acoesautomaticas-pequeno"><i class="ti ti-info-circle"></i> Os chamados filhos ficam na aba <strong>Chamados filhos</strong>'
            . ($novo ? ' (disponível depois de salvar).' : ' (' . count(PluginAcoesautomaticasFilho::daRegra((int) $ID)) . ' configurado(s)).') . '</span>');
        echo $acao('acao_validacao', 'ti ti-thumb-up', 'Pedir validação', $grade($campo('Aprovador', $usuario('acao_validacao_users_id', 'validate_request'))) . self::rico('acao_validacao_texto', $f['acao_validacao_texto'] ?? ''));
        echo $acao('acao_status', 'ti ti-progress', 'Mudar status', $grade($campo('Status', self::selecao('acao_status_valor', Ticket::getAllStatusArray(), $v('acao_status_valor')))),
            'Para solucionar ou deixar pendente use as ações abaixo, que registram solução e motivo.');
        echo $acao('acao_solucionar', 'ti ti-circle-check', 'Solucionar', $grade($campo('Técnico', $usuario('acao_solucionar_users_id', 'own_ticket')), $campo('Categoria (opcional)', $categoria('acao_solucionar_itilcategories_id')))
            . self::rico('acao_solucionar_texto', $f['acao_solucionar_texto'] ?? ''));
        echo $acao('acao_pendente', 'ti ti-player-pause', 'Deixar pendente', $grade($campo('Técnico', $usuario('acao_pendente_users_id', 'own_ticket')),
            $campo('Motivo (opcional)', (string) PendingReason::dropdown(['name' => 'acao_pendente_pendingreasons_id', 'value' => $v('acao_pendente_pendingreasons_id'), 'display' => false, 'width' => '100%'])))
            . self::rico('acao_pendente_followup_texto', $f['acao_pendente_followup_texto'] ?? ''));
        echo $acao('acao_excluir', 'ti ti-trash', 'Excluir o chamado', self::marcar('acao_excluir_purgar', (bool) $v('acao_excluir_purgar'), 'Excluir definitivamente (sem lixeira)'),
            'Depois desta ação nenhuma outra regra roda para o chamado.');

        $vars = '';
        foreach ($C::VARIAVEIS as $var => $desc) {
            $vars .= '<span><code>' . $e($var) . '</code> ' . $e($desc) . '</span>';
        }
        echo '<tr class="tab_bg_1"><td></td><td colspan="3"><div class="acoesautomaticas-variaveis"><strong>Variáveis nos textos:</strong>' . $vars . '</div></td></tr>';

        $this->showFormButtons($options);
        return true;
    }

    // =====================================================================
    // Aba "Testar e executar"
    // =====================================================================

    public function mostrarTeste(): void
    {
        $C = PluginAcoesautomaticasConfig::class;
        $e = [$C, 'e'];
        $id = (int) $this->getID();
        echo $C::assets();
        echo '<div class="acoesautomaticas-pagina" data-acoesautomaticas-teste data-id="' . $id . '" data-ajax="' . $e($C::url('ajax.php')) . '" data-token="' . $e($C::tokenCsrf()) . '">';

        echo '<div class="card acoesautomaticas-card"><div class="card-header"><h5><i class="ti ti-flask"></i> Testar com um chamado</h5></div><div class="card-body">'
            . '<p class="acoesautomaticas-explicacao"><i class="ti ti-info-circle"></i><span>Mostra, condição por condição, se a regra agiria no chamado. Nada é alterado.</span></p>'
            . '<div class="acoesautomaticas-linha"><span class="acoesautomaticas-rotulo-linha">Chamado nº</span><input type="number" min="1" class="form-control form-control-sm acoesautomaticas-numero" data-acoesautomaticas-ticket>'
            . '<button type="button" class="btn btn-sm acoesautomaticas-btn-principal" data-acoesautomaticas-simular><i class="ti ti-flask"></i><span>Testar</span></button></div>'
            . '<div data-acoesautomaticas-resultado-teste></div></div></div>';

        if (self::canUpdate()) {
            echo '<div class="card acoesautomaticas-card"><div class="card-header"><h5><i class="ti ti-player-play"></i> Executar nos chamados existentes</h5></div><div class="card-body">'
                . '<p class="acoesautomaticas-explicacao"><i class="ti ti-info-circle"></i><span>Aplica as ações da regra em todos os chamados que já atendem às condições (fora da lixeira), em lotes. '
                . 'Ignora eventos e janela de execução. Veja a prévia antes.</span></p>'
                . '<div class="acoesautomaticas-linha"><button type="button" class="btn btn-sm btn-ghost-secondary" data-acoesautomaticas-previa><i class="ti ti-list-search"></i><span>Ver prévia</span></button></div>'
                . '<div data-acoesautomaticas-resultado-previa></div>'
                . '<div class="acoesautomaticas-execucao" data-acoesautomaticas-execucao hidden>'
                . '<div class="acoesautomaticas-progresso"><div class="acoesautomaticas-progresso-barra" data-barra></div><span data-pct>0%</span></div>'
                . '<div class="acoesautomaticas-pequeno" data-acoesautomaticas-progresso-texto></div></div>'
                . '</div></div>';
        }
        echo '</div>';
    }
}
