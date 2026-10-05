/* Plugin Ações Automáticas - multiselect, filtro de entidades, formulário da regra (ações, eventos, janela),
 * lista (ligar, ordem por arrastar, duplicar, importar), teste e execução manual em lotes, execuções e configuração. */
(function () {
    'use strict';

    if (window.acoesautomaticasCarregado) {
        return;
    }
    window.acoesautomaticasCarregado = true;

    var esc = function (t) {
        return String(t === null || t === undefined ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    var lerJson = function (texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = String(texto).match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try {
                    return JSON.parse(m[0]);
                } catch (e2) { /* segue */ }
            }
        }
        return { success: false, mensagem: 'Resposta inválida do servidor.' };
    };

    var aviso = function (mensagem, erro) {
        var fn = erro ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function') {
            fn(mensagem);
        }
    };

    var numero = function (n) {
        return Number(n || 0).toLocaleString('pt-BR');
    };

    /** POST para o ajax.php (token CSRF só existe no GLPI 11) */
    var postar = function (raiz, dados, arquivo) {
        var fd = new FormData();
        Object.keys(dados).forEach(function (k) {
            if (Array.isArray(dados[k])) {
                dados[k].forEach(function (v) { fd.append(k + '[]', v); });
            } else {
                fd.append(k, dados[k]);
            }
        });
        if (arquivo) {
            fd.append('arquivo', arquivo);
        }
        var cab = { 'X-Requested-With': 'XMLHttpRequest' };
        var token = raiz.dataset.token || '';
        if (token) {
            fd.append('_glpi_csrf_token', token);
            cab['X-Glpi-Csrf-Token'] = token;
        }
        return fetch(raiz.dataset.ajax, { method: 'POST', body: fd, credentials: 'same-origin', headers: cab })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                var r = lerJson(t);
                if (r.new_token) {
                    raiz.dataset.token = r.new_token;
                }
                return r;
            })
            .catch(function () { return { success: false, mensagem: 'Falha de comunicação com o servidor.' }; });
    };

    var ocupado = function (botao, sim, texto) {
        var span = botao ? botao.querySelector('span') : null;
        if (!botao) {
            return;
        }
        if (sim) {
            botao.disabled = true;
            if (span) {
                botao.dataset.textoOriginal = span.textContent;
                span.textContent = texto || 'Aguarde...';
            }
        } else {
            botao.disabled = false;
            if (span && botao.dataset.textoOriginal !== undefined) {
                span.textContent = botao.dataset.textoOriginal;
            }
        }
    };

    /** Confirmação dentro do botão (sem confirm do navegador): o segundo clique em até 4 s confirma */
    var confirmado = function (botao) {
        if (botao.dataset.armado === '1') {
            botao.dataset.armado = '';
            botao.classList.remove('acoesautomaticas-confirmando');
            return true;
        }
        botao.dataset.armado = '1';
        botao.classList.add('acoesautomaticas-confirmando');
        botao.title = botao.dataset.acoesautomaticasConfirmar || 'Clique de novo para confirmar';
        var span = botao.querySelector('span');
        var original = span ? span.textContent : '';
        if (span) {
            span.textContent = 'Confirmar?';
        }
        setTimeout(function () {
            botao.dataset.armado = '';
            botao.classList.remove('acoesautomaticas-confirmando');
            if (span && span.textContent === 'Confirmar?') {
                span.textContent = original;
            }
        }, 4000);
        return false;
    };

    document.addEventListener('click', function (e) {
        var b = e.target.closest('button[type="submit"][data-acoesautomaticas-confirmar]');
        if (b && !confirmado(b)) {
            e.preventDefault();
        }
    }, true);

    // ------------------------------------------------------------------ entidades filhas ocultas nos seletores do GLPI

    if (window.jQuery && Array.isArray(window.acoesautomaticasEntidadesOcultas) && window.acoesautomaticasEntidadesOcultas.length) {
        var ocultas = window.acoesautomaticasEntidadesOcultas.map(String);
        var filtrar = function (lista) {
            return (lista || []).filter(function (r) {
                if (r.children) {
                    r.children = filtrar(r.children);
                    return r.children.length > 0;
                }
                return ocultas.indexOf(String(r.id)) < 0;
            });
        };
        window.jQuery.ajaxPrefilter(function (opcoes) {
            var dados = typeof opcoes.data === 'string' ? opcoes.data : '';
            if ((opcoes.url || '').indexOf('getDropdownValue') < 0 || !/itemtype=Entity(&|$)/.test(dados)) {
                return;
            }
            var original = opcoes.success;
            opcoes.success = function (resposta) {
                if (resposta && Array.isArray(resposta.results)) {
                    resposta.results = filtrar(resposta.results);
                }
                if (typeof original === 'function') {
                    return original.apply(this, arguments);
                }
            };
        });
    }

    // ------------------------------------------------------------------ multiselect

    var opcoesMs = function (ms) {
        return Array.from(ms.querySelectorAll('.acoesautomaticas-ms-opcao'));
    };

    var atualizarMs = function (ms) {
        var lista = opcoesMs(ms);
        var marcadas = lista.filter(function (o) { return o.querySelector('input').checked; });
        var texto = ms.querySelector('.acoesautomaticas-ms-texto');
        if (!marcadas.length) {
            texto.textContent = ms.dataset.placeholder || 'Qualquer';
        } else if (marcadas.length <= 2) {
            texto.textContent = marcadas.map(function (o) { return o.querySelector('span').textContent; }).join(', ');
        } else {
            texto.textContent = marcadas.length + ' selecionado(s)';
        }
        ms.classList.toggle('acoesautomaticas-ms-ativo', marcadas.length > 0);
        ms.querySelector('.acoesautomaticas-ms-contador').textContent = marcadas.length + ' de ' + lista.length + ' selecionado(s)';
        var visiveis = lista.filter(function (o) { return !o.hidden; });
        var n = visiveis.filter(function (o) { return o.querySelector('input').checked; }).length;
        var todos = ms.querySelector('[data-acoesautomaticas-ms-todos]');
        todos.checked = visiveis.length > 0 && n === visiveis.length;
        todos.indeterminate = n > 0 && n < visiveis.length;
    };

    /** Selecionados primeiro, depois na ordem original da lista */
    var reordenarMs = function (ms) {
        var caixa = ms.querySelector('.acoesautomaticas-ms-opcoes');
        opcoesMs(ms).sort(function (a, b) {
            var ca = a.querySelector('input').checked ? 0 : 1;
            var cb = b.querySelector('input').checked ? 0 : 1;
            return ca !== cb ? ca - cb : Number(a.dataset.ordem) - Number(b.dataset.ordem);
        }).forEach(function (o) { caixa.appendChild(o); });
    };

    var filtrarMs = function (ms) {
        var termo = ms.querySelector('.acoesautomaticas-ms-busca').value.trim().toLowerCase();
        opcoesMs(ms).forEach(function (o) { o.hidden = termo !== '' && o.dataset.label.indexOf(termo) < 0; });
        atualizarMs(ms);
    };

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('[data-acoesautomaticas-ms-abrir]');
        document.querySelectorAll('[data-acoesautomaticas-ms]').forEach(function (ms) {
            var drop = ms.querySelector('.acoesautomaticas-ms-dropdown');
            if (abrir && ms.contains(abrir)) {
                drop.hidden = !drop.hidden;
                if (!drop.hidden) {
                    ms.querySelector('.acoesautomaticas-ms-busca').focus();
                }
            } else if (!ms.contains(e.target)) {
                drop.hidden = true;
            }
        });
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('.acoesautomaticas-ms-busca')) {
            filtrarMs(e.target.closest('[data-acoesautomaticas-ms]'));
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('.acoesautomaticas-ms-busca')) {
            e.preventDefault();
        }
    });

    document.addEventListener('change', function (e) {
        var ms = e.target.closest('[data-acoesautomaticas-ms]');
        if (!ms) {
            return;
        }
        if (e.target.matches('[data-acoesautomaticas-ms-todos]')) {
            opcoesMs(ms).forEach(function (o) {
                if (!o.hidden) {
                    o.querySelector('input').checked = e.target.checked;
                    o.classList.toggle('selected', e.target.checked);
                }
            });
        } else if (e.target.closest('.acoesautomaticas-ms-opcao')) {
            e.target.closest('.acoesautomaticas-ms-opcao').classList.toggle('selected', e.target.checked);
            var busca = ms.querySelector('.acoesautomaticas-ms-busca');
            if (busca.value) {
                busca.value = '';
                filtrarMs(ms);
            }
        } else {
            return;
        }
        reordenarMs(ms);
        atualizarMs(ms);
    });

    // ------------------------------------------------------------------ formulário da regra

    var valorSelect = function (nome) {
        var s = document.querySelector('select[name="' + nome + '"]');
        return s ? s.value : '';
    };

    var ajustarFormulario = function () {
        var eventos = document.querySelector('[data-acoesautomaticas-eventos]');
        if (eventos) {
            eventos.hidden = valorSelect('executar_em') === '0';
        }
        document.querySelectorAll('[data-acoesautomaticas-janela]').forEach(function (tr) {
            tr.hidden = tr.dataset.acoesautomaticasJanela !== valorSelect('range_tipo');
        });
    };

    var mostrarAcao = function (tr) {
        var ligada = tr.querySelector('td:first-child input[type="checkbox"]').checked;
        tr.classList.toggle('acoesautomaticas-acao-ligada', ligada);
        tr.querySelector('.acoesautomaticas-acao-corpo').hidden = !ligada;
        tr.querySelector('.acoesautomaticas-acao-desligada').hidden = ligada;
        if (ligada) {
            window.dispatchEvent(new Event('resize'));
        }
    };

    document.addEventListener('change', function (e) {
        var tr = e.target.closest('[data-acoesautomaticas-acao]');
        if (!tr || !e.target.closest('td:first-child')) {
            return;
        }
        // Solucionar e Pendente se excluem
        var par = { acao_solucionar: 'acao_pendente', acao_pendente: 'acao_solucionar' }[tr.dataset.acoesautomaticasAcao];
        if (par && e.target.checked) {
            var outra = document.querySelector('[data-acoesautomaticas-acao="' + par + '"]');
            var chave = outra ? outra.querySelector('td:first-child input[type="checkbox"]') : null;
            if (chave && chave.checked) {
                chave.checked = false;
                mostrarAcao(outra);
                aviso('"Solucionar" e "Deixar pendente" não podem ficar ligadas juntas: a outra foi desligada.');
            }
        }
        mostrarAcao(tr);
    });

    var iniciarFormulario = function () {
        if (!document.querySelector('[data-acoesautomaticas-acao]')) {
            return;
        }
        ajustarFormulario();
        if (window.jQuery && !window.acoesautomaticasSelectsLigados) {
            window.acoesautomaticasSelectsLigados = true;
            window.jQuery(document).on('change', 'select[name="executar_em"], select[name="range_tipo"]', ajustarFormulario);
        }
    };

    // ------------------------------------------------------------------ lista de regras

    var iniciarLista = function () {
        var raiz = document.querySelector('[data-acoesautomaticas-lista]');
        if (!raiz) {
            return;
        }
        var corpo = raiz.querySelector('[data-acoesautomaticas-ordenar]');
        var filtro = raiz.querySelector('[data-acoesautomaticas-filtro]');
        var tempo = null;
        if (filtro && corpo) {
            filtro.addEventListener('input', function () {
                clearTimeout(tempo);
                tempo = setTimeout(function () {
                    var termo = filtro.value.trim().toLowerCase();
                    corpo.querySelectorAll('tr').forEach(function (tr) { tr.hidden = termo !== '' && tr.dataset.search.indexOf(termo) < 0; });
                }, 300);
            });
        }
        raiz.addEventListener('change', function (e) {
            var chave = e.target.closest('[data-acoesautomaticas-ligar]');
            if (!chave) {
                return;
            }
            postar(raiz, { action: 'ligar', id: chave.dataset.acoesautomaticasLigar, ligada: chave.checked ? 1 : 0 }).then(function (r) {
                aviso(r.mensagem || '', !r.success);
                if (!r.success) {
                    chave.checked = !chave.checked;
                }
                chave.closest('tr').classList.toggle('acoesautomaticas-desligada', !chave.checked);
            });
        });
        raiz.addEventListener('click', function (e) {
            var dup = e.target.closest('[data-acoesautomaticas-duplicar]');
            if (dup) {
                dup.disabled = true;
                postar(raiz, { action: 'duplicar', id: dup.dataset.acoesautomaticasDuplicar }).then(function (r) {
                    if (r.success) {
                        window.location.href = r.url;
                        return;
                    }
                    dup.disabled = false;
                    aviso(r.mensagem || 'Não foi possível duplicar.', true);
                });
            }
        });
        var importar = raiz.querySelector('[data-acoesautomaticas-importar]');
        if (importar) {
            importar.addEventListener('change', function () {
                if (!importar.files.length) {
                    return;
                }
                postar(raiz, { action: 'importar' }, importar.files[0]).then(function (r) {
                    importar.value = '';
                    if (r.success) {
                        window.location.href = r.url;
                        return;
                    }
                    aviso(r.mensagem || 'Não foi possível importar.', true);
                });
            });
        }
        // Ordem por arrastar (pela alça)
        if (!corpo || !raiz.querySelector('.acoesautomaticas-col-mover')) {
            return;
        }
        var arrastando = null;
        corpo.querySelectorAll('tr').forEach(function (tr) {
            var alca = tr.querySelector('.acoesautomaticas-col-mover');
            alca.addEventListener('mousedown', function () { tr.draggable = true; });
            tr.addEventListener('dragstart', function (e) {
                arrastando = tr;
                tr.classList.add('acoesautomaticas-arrastando');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', tr.dataset.id);
            });
            tr.addEventListener('dragend', function () {
                tr.draggable = false;
                tr.classList.remove('acoesautomaticas-arrastando');
                if (!arrastando) {
                    return;
                }
                arrastando = null;
                var ids = Array.from(corpo.querySelectorAll('tr')).map(function (x, i) {
                    x.querySelector('[data-posicao]').textContent = String(i + 1);
                    return x.dataset.id;
                });
                postar(raiz, { action: 'ordenar', ids: ids }).then(function (r) { aviso(r.mensagem || '', !r.success); });
            });
        });
        corpo.addEventListener('dragover', function (e) {
            if (!arrastando) {
                return;
            }
            e.preventDefault();
            var alvo = e.target.closest('tr');
            if (!alvo || alvo === arrastando) {
                return;
            }
            var r = alvo.getBoundingClientRect();
            corpo.insertBefore(arrastando, e.clientY > r.top + r.height / 2 ? alvo.nextSibling : alvo);
        });
    };

    // ------------------------------------------------------------------ testar e executar

    var iniciarTeste = function (raiz) {
        if (!raiz || raiz.dataset.iniciado) {
            return;
        }
        raiz.dataset.iniciado = '1';
        var resultado = raiz.querySelector('[data-acoesautomaticas-resultado-teste]');
        var campoTicket = raiz.querySelector('[data-acoesautomaticas-ticket]');
        var simular = function () {
            var b = raiz.querySelector('[data-acoesautomaticas-simular]');
            if (!campoTicket.value) {
                aviso('Informe o número do chamado.', true);
                return;
            }
            ocupado(b, true, 'Testando...');
            postar(raiz, { action: 'simular', id: raiz.dataset.id, ticket: campoTicket.value }).then(function (r) {
                ocupado(b, false);
                if (!r.success || !r.ok) {
                    resultado.innerHTML = '<div class="acoesautomaticas-alerta acoesautomaticas-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>' + esc(r.mensagem || 'Não foi possível testar.') + '</span></div>';
                    return;
                }
                resultado.innerHTML = '<div class="acoesautomaticas-alerta ' + (r.passa ? 'acoesautomaticas-alerta-ok' : 'acoesautomaticas-alerta-aviso') + '"><i class="ti ti-' + (r.passa ? 'circle-check' : 'circle-x') + '"></i><span>'
                    + (r.passa ? 'A regra <strong>agiria</strong> em ' : 'A regra <strong>não agiria</strong> em ') + '<a href="' + esc(r.url) + '">' + esc(r.ticket) + '</a> (se o evento for: ' + esc(r.eventos) + ').</span></div>'
                    + '<table class="table table-sm acoesautomaticas-tabela acoesautomaticas-checklist"><tbody>'
                    + r.itens.map(function (i) {
                        return '<tr><td class="acoesautomaticas-col-ok"><i class="ti ti-' + (i.ok ? 'circle-check text-success' : 'circle-x text-danger') + '"></i></td><td><strong>' + esc(i.rotulo) + '</strong></td><td class="acoesautomaticas-pequeno">' + esc(i.detalhe) + '</td></tr>';
                    }).join('') + '</tbody></table>'
                    + '<div class="acoesautomaticas-pequeno"><strong>Ações:</strong> ' + (r.acoes.length ? r.acoes.map(function (a) { return '<span class="acoesautomaticas-etiqueta">' + esc(a) + '</span>'; }).join(' ') : 'nenhuma ação ligada') + '</div>';
            });
        };
        raiz.querySelector('[data-acoesautomaticas-simular]').addEventListener('click', simular);
        campoTicket.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                simular();
            }
        });

        var btnPrevia = raiz.querySelector('[data-acoesautomaticas-previa]');
        if (!btnPrevia) {
            return;
        }
        var areaPrevia = raiz.querySelector('[data-acoesautomaticas-resultado-previa]');
        var execucao = raiz.querySelector('[data-acoesautomaticas-execucao]');
        var previa = null;
        btnPrevia.addEventListener('click', function () {
            ocupado(btnPrevia, true, 'Calculando...');
            postar(raiz, { action: 'previa', id: raiz.dataset.id }).then(function (r) {
                ocupado(btnPrevia, false);
                if (!r.success) {
                    aviso(r.mensagem || 'Falha na prévia.', true);
                    return;
                }
                previa = r;
                var texto = r.parcial
                    ? 'Dos ' + numero(r.candidatos) + ' chamados que passam pelos filtros, os primeiros ' + numero(r.avaliados) + ' foram conferidos: <strong>' + numero(r.batem) + '</strong> atendem a todas as condições.'
                    : '<strong>' + numero(r.batem) + '</strong> chamado(s) atendem a todas as condições.';
                areaPrevia.innerHTML = '<div class="acoesautomaticas-alerta acoesautomaticas-alerta-info"><i class="ti ti-info-circle"></i><span>' + texto + '</span></div>'
                    + (r.amostra.length ? '<div class="acoesautomaticas-amostra">' + r.amostra.map(function (t) {
                        return '<a href="' + esc(t.url) + '" class="acoesautomaticas-etiqueta" title="' + esc(t.status) + '">#' + t.id + ' ' + esc(t.titulo) + '</a>';
                    }).join(' ') + (r.batem > r.amostra.length ? ' <span class="acoesautomaticas-pequeno">e outros</span>' : '') + '</div>' : '')
                    + (r.batem > 0 ? '<div class="acoesautomaticas-linha mt-2"><button type="button" class="btn btn-sm btn-danger" data-acoesautomaticas-executar data-acoesautomaticas-confirmar="As ações serão aplicadas e não podem ser desfeitas"><i class="ti ti-player-play"></i><span>Executar agora</span></button></div>' : '');
            });
        });
        raiz.addEventListener('click', function (e) {
            var b = e.target.closest('[data-acoesautomaticas-executar]');
            if (!b || !confirmado(b)) {
                return;
            }
            b.disabled = true;
            execucao.hidden = false;
            var total = previa ? Math.max(1, previa.batem) : 1;
            var feitos = 0;
            var erros = 0;
            var barra = execucao.querySelector('[data-barra]');
            var pct = execucao.querySelector('[data-pct]');
            var texto = execucao.querySelector('[data-acoesautomaticas-progresso-texto]');
            var lote = function (apos) {
                postar(raiz, { action: 'lote', id: raiz.dataset.id, apos: apos }).then(function (r) {
                    if (!r.success) {
                        texto.textContent = r.mensagem || 'Falha na execução.';
                        aviso(r.mensagem || 'Falha na execução.', true);
                        return;
                    }
                    feitos += r.executados;
                    erros += r.erros;
                    var p = r.fim ? 100 : Math.min(99, Math.round(feitos / total * 100));
                    barra.style.width = p + '%';
                    pct.textContent = p + '%';
                    texto.textContent = numero(feitos) + ' chamado(s) processado(s)' + (erros ? ' · ' + erros + ' com erro (veja em Execuções)' : '');
                    if (r.fim) {
                        aviso('Execução concluída: ' + numero(feitos) + ' chamado(s).');
                        return;
                    }
                    lote(r.apos);
                });
            };
            lote(0);
        });
    };

    // ------------------------------------------------------------------ execuções e configuração

    var iniciarLogs = function () {
        var raiz = document.querySelector('[data-acoesautomaticas-logs]');
        if (!raiz) {
            return;
        }
        var sel = raiz.querySelector('[data-acoesautomaticas-por-pagina]');
        if (sel) {
            sel.addEventListener('change', function () { window.location.href = sel.value; });
        }
        var limpar = raiz.querySelector('[data-acoesautomaticas-limpar-logs]');
        if (limpar) {
            limpar.addEventListener('click', function () {
                if (!confirmado(limpar)) {
                    return;
                }
                ocupado(limpar, true, 'Apagando...');
                postar(raiz, { action: 'limpar_logs', dias: 30 }).then(function (r) {
                    if (r.success) {
                        window.location.reload();
                        return;
                    }
                    ocupado(limpar, false);
                    aviso(r.mensagem || 'Não foi possível apagar.', true);
                });
            });
        }
    };

    var iniciarConfig = function () {
        var raiz = document.querySelector('[data-acoesautomaticas-config]');
        if (!raiz) {
            return;
        }
        raiz.querySelectorAll('[data-aba]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                raiz.querySelectorAll('[data-aba]').forEach(function (x) { x.classList.toggle('active', x === a); });
                raiz.querySelectorAll('[data-aba-painel]').forEach(function (p) { p.hidden = p.dataset.abaPainel !== a.dataset.aba; });
                raiz.querySelectorAll('[data-acoesautomaticas-aba-atual]').forEach(function (h) { h.value = a.dataset.aba; });
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('aba', a.dataset.aba);
                    window.history.replaceState(null, '', url.toString());
                } catch (err) { /* navegador antigo */ }
            });
        });
        raiz.querySelectorAll('[data-acoesautomaticas-busca-tabela]').forEach(function (inp) {
            var tabela = inp.closest('.card-body').querySelector('table');
            var tempo = null;
            inp.addEventListener('input', function () {
                clearTimeout(tempo);
                tempo = setTimeout(function () {
                    var termo = inp.value.trim().toLowerCase();
                    tabela.querySelectorAll('tbody tr[data-linha]').forEach(function (tr) { tr.hidden = termo !== '' && tr.dataset.search.indexOf(termo) < 0; });
                }, 300);
            });
        });
    };

    var iniciar = function () {
        document.querySelectorAll('[data-acoesautomaticas-ms]').forEach(atualizarMs);
        iniciarFormulario();
        iniciarLista();
        document.querySelectorAll('[data-acoesautomaticas-teste]').forEach(iniciarTeste);
        iniciarLogs();
        iniciarConfig();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
    // Abas do GLPI carregadas depois (Testar e executar)
    if (window.jQuery) {
        window.jQuery(document).ajaxComplete(function (ev, xhr, opcoes) {
            if (opcoes && /common\.tabs\.php/.test(opcoes.url || '')) {
                setTimeout(function () {
                    iniciarFormulario();
                    document.querySelectorAll('[data-acoesautomaticas-teste]').forEach(iniciarTeste);
                    document.querySelectorAll('[data-acoesautomaticas-ms]').forEach(atualizarMs);
                }, 50);
            }
        });
    }
})();
