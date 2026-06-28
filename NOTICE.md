# NOTICE

Este projeto inclui a "Central de Atendimento" (módulo de inbox de conversas multicanal,
iniciado em `specs/010-inbox-whatsapp-tempo-real/`), cuja arquitetura de domínio e fluxos
foram adaptados a partir do projeto open-source **Chatwoot**.

Portions adapted from Chatwoot (https://github.com/chatwoot/chatwoot),
Copyright (c) Chatwoot Inc., licenciado sob a licença MIT Expat.

A adaptação é de lógica e estrutura (modelos de domínio Account/Inbox/Contact/
ContactInbox/Conversation/Message, abstração de canal, fluxo de mensagem entrante/saída,
modelo de eventos em tempo real), traduzida de Ruby on Rails/Vue.js para PHP/Laravel e
TypeScript/Next.js — não há cópia literal de código-fonte ou texto/comentários do projeto
original.

Funcionalidades equivalentes às disponíveis apenas na pasta `enterprise/` do Chatwoot
(licença proprietária própria, ver `enterprise/LICENSE` no repositório do Chatwoot — política
de SLA e assistente de IA) foram ou serão desenhadas de forma independente, sem uso do
código-fonte proprietário como referência de implementação.

## Texto da licença MIT (Chatwoot)

```
MIT License

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
```
