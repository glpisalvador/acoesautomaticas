<?php

/**
 * Plugin Ações Automáticas - atalho para a configuração
 */

Session::checkLoginUser();
Html::redirect(PluginAcoesautomaticasConfig::url('config.form.php'));
