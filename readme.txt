=== GeoLang – Multilingual Manager ===
Contributors: geolang
Tags: multilingual, elementor, translation, language switcher, dynamic tags
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.30
License: GPLv2 or later

Gerenciador de conteúdo multilíngue para páginas Elementor Pro (PT / EN / ES).

== Descrição ==

O GeoLang – Multilingual Manager permite que qualquer página construída com Elementor Pro
tenha versões de conteúdo em Português (PT), Inglês (EN) e Espanhol (ES), sem dependência
de plugins externos como WPML ou Polylang.

Funcionalidades principais:

* Switcher de idiomas com bandeiras (shortcode [geolang_switcher] ou widget Elementor)
* Dynamic Tags para usar traduções diretamente em qualquer widget Elementor Pro
* Widget "GeoLang Field Manager" para cadastrar campos direto no editor Elementor
* Painel admin centralizado para editar todas as traduções sem abrir o Elementor
* Persistência de sessão via cookie (30 dias) — compatível com cache de página
* Segurança: nonces, capability checks, sanitização e prepared statements em tudo

== Requisitos ==

* WordPress 6.0 ou superior
* Elementor Pro 3.0 ou superior (obrigatório)
* PHP 7.4 ou superior

== Instalação ==

1. Faça upload da pasta `geolang-multilingual` para `/wp-content/plugins/`.
2. Ative o plugin em Plugins → Plugins instalados.
3. Certifique-se de que o Elementor Pro está ativo.
4. Acesse GeoLang → Configurações para definir o idioma padrão.

== Como usar ==

=== Fluxo básico ===

1. Abra qualquer página no Elementor.
2. Arraste o widget **GeoLang Field Manager** para a página.
3. Defina a chave do campo (ex: `hero_title`), escolha o tipo (texto/URL/imagem)
   e preencha o conteúdo nos 3 idiomas.
4. Salve a página — o plugin sincroniza automaticamente com o banco de dados.
5. Em outro widget (ex: Heading), clique em Dynamic Tags → **GeoLang – Texto** →
   selecione `hero_title`.
6. Adicione o shortcode `[geolang_switcher]` em qualquer área do site (header, footer, etc.)
   ou use o widget **GeoLang Switcher** no Elementor.

=== Shortcode do switcher ===

  [geolang_switcher]                         → bandeiras, tamanho médio
  [geolang_switcher style="flags-text"]      → bandeiras + sigla do idioma
  [geolang_switcher style="text"]            → só siglas (PT / EN / ES)
  [geolang_switcher size="sm"]               → bandeiras pequenas (24px)
  [geolang_switcher size="lg"]               → bandeiras grandes (40px)

=== Dynamic Tags disponíveis ===

* GeoLang – Texto: para campos text (títulos, parágrafos, botões)
* GeoLang – Imagem: para campos image (retorna URL + ID do attachment)
* GeoLang – URL/Link: para campos url (links de botões, hrefs)

=== Painel de traduções ===

Acesse GeoLang → Gerenciar Traduções para ver e editar todos os campos
cadastrados em todas as páginas, com filtros por post, tipo e busca por chave.

=== Link direto por idioma ===

Você pode forçar um idioma via parâmetro GET:
  https://seusite.com/pagina/?lang=en

O idioma é persistido em cookie após o primeiro acesso.

== Compatibilidade com cache ==

O idioma é gerenciado 100% via cookie lido no JavaScript, não no PHP que renderiza
o HTML. Isso garante que páginas cacheadas (WP Rocket, LiteSpeed, W3TC, etc.)
funcionem corretamente — cada visitante vê o idioma do seu próprio cookie.

== Desinstalação ==

Ao desinstalar o plugin em Plugins → Excluir, todos os dados são removidos:
tabela `{prefix}geolang_strings`, opções do WordPress e transients.

== Changelog ==

= 1.0.30 =
* Novo: shortcode [geolang key="..."] — retorna o texto traduzido de um campo GeoLang no idioma atual. Use em campos de texto de qualquer plugin que processe shortcodes. Suporta atributos opcionais: fallback="texto padrão" e post_id="42".

= 1.0.29 =
* Melhoria: trocar a bandeira de idioma agora recarrega a página automaticamente — garante que todos os elementos PHP-side (categorias WooCommerce, menus, filtros de terceiros) reflitam o novo idioma sem precisar dar F5 manualmente.

= 1.0.28 =
* Fix: nome da categoria WooCommerce agora troca instantaneamente ao clicar na bandeira — PHP emite <span class="geolang-cat-name" data-lang-pt/en/es> antes do <h2> do WC; JS localiza o span, caminha até o <h2> irmão e substitui o nó de texto, preservando o badge de contagem de produtos.

= 1.0.27 =
* Fix: imagem da categoria WooCommerce agora troca instantaneamente ao clicar na bandeira (sem reload) — substituída a renderização padrão do WooCommerce por <img class="geolang-image" data-lang-pt/en/es>, aproveitando o mesmo mecanismo JS já existente para imagens.
* Fix: flag $bypass_thumbnail_filter evita recursão ao ler o thumbnail_id original dentro do override.

= 1.0.26 =
* Novo: imagem por idioma nas categorias de produto WooCommerce — em Produtos → Categorias → editar categoria, agora aparecem os campos "📷 Imagem EN" e "📷 Imagem ES" com seletor de mídia nativo do WordPress.
* Técnica: filtro get_term_metadata intercepta thumbnail_id no frontend e retorna o attachment ID do idioma ativo quando definido; sem risco de recursão (chaves internas são geolang_image_en/es, não thumbnail_id).

= 1.0.25 =
* Novo: visibilidade por idioma — adicione a classe CSS geolang-only-pt, geolang-only-en ou geolang-only-es em qualquer seção/coluna/widget do Elementor (aba Avançado → Classes CSS) para exibir aquele componente apenas quando o idioma correspondente estiver ativo.
* Técnica: script inline no <head> (< 300 bytes) define data-geolang no elemento <html> antes do body renderizar — zero flash de conteúdo errado mesmo em páginas cacheadas.
* Troca de idioma atualiza o atributo data-geolang instantaneamente → CSS reage em < 1ms sem recarregar a página.
* Editor Elementor: seções com geolang-only-* recebem badge colorido (🇧🇷 PT / 🇺🇸 EN / 🇪🇸 ES) para o designer identificar facilmente qual versão está editando.

= 1.0.24 =
* Novo: suporte a HTML completo nos campos de tradução — tags <p>, <ul>, <table> e estilos inline (style="font-weight:600; color:#d97a0f;") são preservados ao salvar.
* Melhoria: wrapper automático <div> vs <span> — campos com HTML de bloco usam <div class="geolang-field"> para evitar HTML inválido; campos de texto simples continuam com <span>.
* Melhoria: sanitização estendida (GeoLang_Core::kses_html) permite atributos style e class em todos os elementos HTML comuns, sem comprometer a segurança.

= 1.0.22 =
* Fix: tradução de menus não funcionava ao clicar na bandeira — itens de menu agora recebem span geolang-field com os 3 idiomas, aproveitando o mesmo mecanismo JS do resto do site para troca instantânea sem reload.

= 1.0.21 =
* Fix: Dynamic Tag "GeoLang – Texto" mostrava HTML cru em botões, inputs e placeholders no frontend — agora emite sempre texto puro.
* Novo: registry GeoLangDT no footer + substituição por nós de texto no DOM — troca instantânea de idioma funciona mesmo sem o span (cobre text nodes, placeholder e value attributes).

= 1.0.20 =
* Fix: Dynamic Tag "GeoLang – Texto" em botões mostrava HTML cru no editor Elementor — agora exibe texto puro no editor e span com data attributes no frontend.
* Novo: botão "🗑️ Excluir selecionados" em Gerenciar Traduções — apaga em lote as linhas marcadas com confirmação.
* Melhoria: botões "Traduzir selecionados" e "Excluir selecionados" agrupados acima da tabela.

= 1.0.19 =
* Fix: botão "Verificar agora" não respondia — script estava antes do HTML do botão (sem document.ready).
* Novo: botão "🗑️ Limpar importados" em Gerenciar Traduções — remove todas as entradas com chave elem_* geradas pelo botão de importação, sem afetar campos criados manualmente com Dynamic Tags.

= 1.0.18 =
* Teste de auto-updater via GitHub Releases.

= 1.0.17 =
* Novo: botão "📥 Importar textos do Elementor" em Gerenciar Traduções — escaneia todas as páginas Elementor e importa textos de widgets (Heading, Button, Text Editor, Icon Box, Image Box, etc.) diretamente para a tabela de traduções, preservando traduções EN/ES já existentes.
* Novo: tradução automática de widgets Elementor no frontend via `elementor/frontend/widget/before_render_content` — não precisa de Dynamic Tag para textos importados.

= 1.0.16 =
* Bump de versão para teste do auto-updater via GitHub Releases.

= 1.0.15 =
* Melhoria: auto-updater agora funciona sem configuração — repositório público fixo, WordPress detecta novas versões automaticamente.

= 1.0.14 =
* Novo: auto-updater via GitHub Releases — configure o repo em GeoLang → Configurações e o WordPress detecta novas versões automaticamente com 1 clique para instalar.

= 1.0.13 =
* Fix: sincronização Elementor → Gerenciar Traduções agora usa save_post + _elementor_data (confiável em todas as versões do Elementor).
* Novo: botão "🔄 Re-sincronizar Elementor" em Gerenciar Traduções — importa imediatamente todos os campos de todas as páginas Elementor existentes.

= 1.0.12 =
* Novo: campo field_key agora é opcional no widget Field Manager — gerado automaticamente do texto PT (slug + ID do elemento Elementor).
* Novo: Gerenciar Traduções tem coluna de checkboxes por linha + "Selecionar todos".
* Novo: botão "✨ Traduzir selecionados" — traduz apenas as linhas marcadas, sem reprocessar o resto.
* Melhoria: busca em Gerenciar Traduções agora encontra por texto PT além da chave.
* Melhoria: filtro por página já exibe título do post no dropdown.

= 1.0.11 =
* Fix: OpenRouter — substituídos modelos gratuitos (instáveis) por modelos pagos baratos da OpenAI (gpt-4o-mini padrão, gpt-4.1-nano, gpt-4.1-mini, gpt-4o, gpt-3.5-turbo, Mistral).
* Fix: mensagem de erro do OpenRouter agora exibe modelo, código HTTP e mensagem completa da API para facilitar diagnóstico.

= 1.0.10 =
* Fix: GeoLang Field Manager widget agora troca idioma instantaneamente sem recarregar página (span com data-lang-* attributes).
* Fix: OpenRouter — modelo padrão trocado para Mistral 7B Instruct (mais estável no tier gratuito); adicionado GPT-4o Mini como opção.
* Novo: campos EN/ES de tradução de menus agora aparecem diretamente em Aparência → Menus em cada item, sem precisar da página separada.

= 1.0.9 =
* Fix: links/botões com URL GeoLang agora trocam instantaneamente ao mudar de idioma (GeoLangURLData footer JSON + JS DOM update).
* Fix: sync Elementor → Gerenciar Traduções corrigido para suportar diferentes versões (data['content'] fallback + arrays __dynamic__ já decodificados).
* Fix: bandeiras ativas agora ficam 15% maiores (scale 1.15) sem borda ou padding.
* Novo: tradução de categorias e tags de produtos WooCommerce via term_meta (campos EN/ES na tela de edição de categoria).
* Novo: tradução de menus de navegação — página GeoLang → Menus com tabela editável por menu.
* Novo: integração OpenRouter AI — configuração em GeoLang → Configurações, botão "✨ IA" por linha em Gerenciar Traduções, tradução em lote, HTML-aware.
* Melhoria: Gerenciar Traduções agora exibe o título real do post (não só o ID).
* Melhoria: campos URL/imagem em Gerenciar Traduções mostram badge visual sem botão de IA.

= 1.0.8 =
* Integração com WooCommerce: novo módulo `class-geolang-woocommerce.php` com filtros PHP para título, descrição longa e descrição curta de produtos.
* Meta box: seção dedicada em produtos WooCommerce com campos pré-preenchidos (Título, Descrição, Desc. curta) usando chaves reservadas `_wc_title`, `_wc_description`, `_wc_short_desc`.
* Meta box: campos WC pré-carregados com o conteúdo PT atual do produto; colunas EN e ES prontas para tradução.
* Atualização automática de idioma no frontend via filtros PHP sem necessidade de recarregar página.

= 1.0.7 =
* Remove dependência do Elementor Pro — agora compatível com Pro Elements e Elementor free.
* Dynamic Tags registradas apenas quando o módulo DynamicTags está presente.

= 1.0.0 =
* Lançamento inicial.
* Dynamic Tags: GeoLang Texto, GeoLang Imagem, GeoLang URL.
* Widget GeoLang Field Manager e GeoLang Switcher para Elementor.
* Shortcode [geolang_switcher] com estilos flags / text / flags-text.
* Painel admin com filtros, paginação e edição inline via modal.
* Suporte a 3 idiomas: PT, EN, ES.
* Segurança completa: nonces, capabilities, sanitização, prepared statements, rate limiting.
