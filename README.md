# Hiperstorm – Botão de Comentários v2

Plugin WordPress que adiciona o shortcode `[hs_comentarios_botao]` para abrir comentários em **modal via AJAX** no desktop e, opcionalmente, usar **página dedicada** no mobile (ou sempre, dependendo do modo).

## Requisitos

- WordPress com comentários habilitados no post.
- O shortcode funciona em posts (`is_singular('post')`).

## Instalação

1. Copie o plugin para a pasta de plugins do WordPress.
2. Ative o plugin no painel.
3. Insira o shortcode no conteúdo do post.

---

## Uso básico

```text
[hs_comentarios_botao]
```

Com esse formato padrão, o plugin:

- Mostra o botão de comentários.
- Exibe quantidade de comentários no texto do botão (quando aplicável).
- Usa modo `modal_desktop_page_mobile` (modal em desktop, página em mobile).

---

## Todas as possibilidades de customização do shortcode

### Atributos suportados

```text
[hs_comentarios_botao
  texto=""
  mostrar_quantidade="yes"
  class=""
  cor_fundo=""
  cor_texto=""
  alinhar="left"
  width="block"
  margin=""
  modo="modal_desktop_page_mobile"
  pagina_url=""
]
```

### 1) `texto`
Define manualmente o texto do botão.

- Padrão: vazio (`""`).
- Quando preenchido, substitui completamente o texto automático.

Exemplo:

```text
[hs_comentarios_botao texto="Ver comentários"]
```

### 2) `mostrar_quantidade`
Controla se o botão usa contagem automática de comentários quando `texto` está vazio.

- Valores esperados: `yes` ou qualquer outro valor.
- Padrão: `yes`.

Comportamento:

- `yes`:
  - 0 comentários → `Comentários`
  - 1 comentário → `1 comentário`
  - 2+ comentários → `N comentários`
- qualquer valor diferente de `yes`:
  - sempre `Comentários`

Exemplo:

```text
[hs_comentarios_botao mostrar_quantidade="no"]
```

### 3) `class`
Adiciona uma classe CSS extra ao botão (`.hs-comentarios-botao`).

- Padrão: vazio.
- Útil para aplicar estilo customizado no tema.

Exemplo:

```text
[hs_comentarios_botao class="meu-botao-comentarios"]
```

### 4) `cor_fundo`
Define a cor de fundo inline do botão.

- Padrão: vazio (sem sobrescrever).
- Formatos aceitos:
  - Hex (`#fff`, `#ffffff`)
  - `rgb(...)`
  - `rgba(...)`
  - `hsl(...)`
  - `hsla(...)`
- Valor inválido é ignorado.

Exemplo:

```text
[hs_comentarios_botao cor_fundo="#111111"]
```

### 5) `cor_texto`
Define a cor do texto inline do botão.

- Padrão: vazio (sem sobrescrever).
- Mesmos formatos e regras de validação de `cor_fundo`.

Exemplo:

```text
[hs_comentarios_botao cor_texto="#ffffff"]
```

### 6) `alinhar`
Alinha o botão no container.

- Valores válidos: `left`, `center`, `right`.
- Padrão: `left`.
- Valor inválido cai para `left`.

Exemplo:

```text
[hs_comentarios_botao alinhar="center"]
```

### 7) `width`
Controla como o botão ocupa largura.

- Valores válidos: `block` ou `full`.
- Padrão: `block`.
- `block`: ocupa 100% da largura do container onde o shortcode foi inserido.
- `full`: ocupa toda a largura da viewport (útil para mobile).
- Valor inválido cai para `block`.

Exemplos:

```text
[hs_comentarios_botao width="block"]
[hs_comentarios_botao width="full"]
```

### 8) `margin`
Define margem externa em pixels para o widget (wrapper do shortcode).

- Padrão: vazio (mantém margem padrão do CSS do plugin).
- Aceita apenas números inteiros sem unidade (ex.: `12`, `24`, `0`).
- Valor inválido é ignorado.

Exemplo:

```text
[hs_comentarios_botao margin="16"]
```

### 9) `modo`
Controla como os comentários serão abertos.

- Valores válidos:
  - `modal` → sempre abre modal.
  - `page` → sempre redireciona para página de comentários.
  - `modal_desktop_page_mobile` → modal no desktop, página no mobile.
- Padrão: `modal_desktop_page_mobile`.
- Valor inválido cai para `modal_desktop_page_mobile`.

Exemplos:

```text
[hs_comentarios_botao modo="modal"]
[hs_comentarios_botao modo="page"]
[hs_comentarios_botao modo="modal_desktop_page_mobile"]
```

### 10) `pagina_url`
Define uma URL personalizada para a página de comentários (usada nos modos com navegação por página).

- Padrão: vazio.
- Quando preenchido, o plugin adiciona `post_id` na query string.
- Quando vazio, usa rota interna do plugin com query vars:
  - `?hs_comentarios_page=1&post_id=ID_DO_POST`

Exemplo:

```text
[hs_comentarios_botao modo="page" pagina_url="https://seusite.com/comentarios"]
```

URL resultante (exemplo):

```text
https://seusite.com/comentarios?post_id=123
```

---

## Exemplos prontos (combinações úteis)

### Botão simples com comportamento padrão

```text
[hs_comentarios_botao]
```

### Botão com visual customizado

```text
[hs_comentarios_botao
  texto="Comentar"
  cor_fundo="#0052cc"
  cor_texto="#ffffff"
  alinhar="center"
  width="full"
  margin="12"
]
```

### Forçar modal em qualquer dispositivo

```text
[hs_comentarios_botao modo="modal"]
```

### Forçar página dedicada e esconder contagem

```text
[hs_comentarios_botao modo="page" mostrar_quantidade="no"]
```

### Página dedicada customizada

```text
[hs_comentarios_botao
  modo="page"
  pagina_url="https://seusite.com/minha-pagina-de-comentarios"
]
```

---

## Personalizações além do shortcode

### CSS base disponível

O plugin registra a folha:

- `assets/hs-comentarios-botao.css`

Classes principais para customização visual no tema:

- `.hs-comentarios-botao-wrap`
- `.hs-comentarios-botao`
- `.hs-comentarios-modal`
- `.hs-comentarios-modal__overlay`
- `.hs-comentarios-modal__dialog`
- `.hs-comentarios-modal__body`
- `.hs-comentarios-modal__close`
- `.hs-comentarios-modal__title`
- `.hs-comentarios-container`
- `.hs-comentarios-loading`
- `.hs-comentarios-paginacao`
- `.hs-comentarios-page-link`

### Breakpoint de mobile

No modo `modal_desktop_page_mobile`, a decisão desktop/mobile usa breakpoint de **768px** no script.

---

## Fluxo funcional resumido

- Clique no botão:
  - `modal` → carrega comentários via AJAX em modal.
  - `page` → redireciona para página de comentários.
  - `modal_desktop_page_mobile` → modal em desktop e página em mobile.
- Envio de novo comentário no modal:
  - Envia via `fetch`.
  - Recarrega a listagem no modal após sucesso.
- Paginação de comentários:
  - Modal: botões AJAX (sem sair do modal).
  - Página dedicada: links com `?cpage=`.

---

## Observações importantes

- O shortcode não renderiza fora de posts.
- Se comentários estiverem fechados e sem comentários existentes, o botão não é exibido.
- O plugin inclui nonce na chamada AJAX de carregamento para validação da requisição.
