# Ações Automáticas para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

Plugin que automatiza o tratamento de **chamados**. Você cria regras do tipo "quando acontecer X e o chamado tiver Y, faça Z", e o plugin executa sozinho. Tudo é feito pelas classes nativas do GLPI, então fica no histórico do chamado e dispara as notificações normais.

## O que o plugin faz

### Regras de automação
Cada regra é um **item nativo** do GLPI: tem lista com busca, formulário, histórico de alterações e direitos por perfil. Dá para duplicar, exportar e importar regras em JSON.

- **Quando executar:**
  - na criação, na atualização ou nas duas;
  - na atualização, dá para escolher os eventos: mudança de status, de categoria ou de prioridade; técnico, requerente, observador, grupo técnico ou grupo observador adicionados; acompanhamento, tarefa ou solução adicionados; qualquer outra alteração.
- **Janela de validade:** sempre, só num horário do dia ou só num período de datas.
- **Condições** em lista, com vários valores em cada uma (vazio significa "qualquer"):
  - entidades (com ou sem subentidades), tipos, status, prioridades, urgências, categorias e origens;
  - requerentes, grupos atribuídos, grupos observadores e "sem técnico atribuído".
- **Ações:**
  - atender, atribuindo o técnico e a categoria;
  - categorizar; mudar status, prioridade ou urgência;
  - atribuir grupo técnico;
  - adicionar observador ou grupo observador;
  - adicionar acompanhamento, público ou privado;
  - colocar em **pendente** com motivo e texto;
  - pedir **validação** a um usuário;
  - **solucionar** com texto, autor e categoria;
  - criar **chamados filhos** vinculados;
  - excluir ou apagar de vez.
- **Ordem:** as regras rodam na ordem da lista, e cada regra pode **parar** as seguintes depois de executar.
- **Sem laço infinito:** o que o próprio plugin grava nunca dispara as regras de novo, incluindo os chamados filhos criados por uma regra.

### Teste de regra
Escolha um chamado e veja, **sem alterar nada**, quais condições passam, quais falham e o que a regra faria.

### Execução manual em lotes
Aplique uma regra a chamados que já existem. O processamento é feito em lotes, com o andamento na tela.

### Detecção de duplicados
Na criação, antes das regras, o plugin procura um chamado aberto igual do mesmo requerente, nos últimos dias configurados e, se quiser, só na mesma entidade. Ao encontrar, ele:
- soluciona o chamado novo como duplicado, com o texto, o autor e a categoria configurados;
- vincula o chamado novo ao original;
- pode avisar no chamado original.

### Registro e manutenção
- Cada execução fica registrada: regra, chamado e ações feitas.
- A ação automática, de hora em hora, desliga as regras cujo período terminou e apaga os registros antigos.

## Configuração e direitos

- **Direito nativo** "Ações automáticas" na aba do perfil. A instalação o libera para os perfis que administram o GLPI.
- A página de configuração reúne a detecção de duplicados e a retenção dos registros.

---

## Download e instalação

1. Baixe o arquivo `acoesautomaticas-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/acoesautomaticas/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/acoesautomaticas
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install acoesautomaticas -u <usuário administrador>
   php bin/console plugin:activate acoesautomaticas
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/acoesautomaticas` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install acoesautomaticas -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).