# Windows + XAMPP

## Objetivo

Subir o backend e a operacao local de forma previsivel, sem caminhos absolutos espalhados pelo codigo.

## Regras

- O Apache do XAMPP tambem reserva a porta `8000` para `C:\xampp\htdocs\sistema-erp\backend\public`.
- O Apache do XAMPP tambem reserva a porta `8080` para `C:\xampp\htdocs\sistema-erp\frontends\desktop\public`.
- O Apache do XAMPP nao deve reservar a porta `3001`; ela fica preferencialmente disponivel para o `frontends/mobile` em desenvolvimento.
- O Apache do XAMPP nao deve reservar a porta `3002`; ela pertence ao `frontends/chat` em desenvolvimento.
- O Apache do XAMPP nao deve reservar a porta `8090`; ela pertence ao processo local do Laravel Reverb.
- O backend deve ler caminhos de `backend/.env.example` e do `.env` real.
- As filas podem usar `sync` enquanto nao houver jobs assincronos.
- O scheduler pode rodar via Task Scheduler com `php artisan schedule:run`.

## Templates

- `infra/windows/apache-vhost.conf`
- `infra/windows/hosts.example`
- `scripts/powershell/validate-dev-env.ps1`

## Observacao de operacao

O ambiente local nao deve servir `C:\xampp\htdocs\sistema-erp` como raiz publica.
O acesso ao backend central deve ser feito por `http://127.0.0.1:8000` via Apache/XAMPP, nao por `php artisan serve`.
O acesso ao desktop deve ser feito por `http://127.0.0.1:8080` via Apache/XAMPP, nao por `php artisan serve`.
O acesso ao mobile deve ser feito via `pnpm dev` em `frontends/mobile`, preferindo `http://127.0.0.1:3001`; se a porta estiver ocupada no bind IPv4 local (`127.0.0.1`) ou no bind IPv6 usado pelo Next (`::`), o script sobe o Next na proxima porta livre e informa a URL no terminal.
O acesso ao chat deve ser feito via `npm run dev` em `frontends/chat`, usando `http://127.0.0.1:3002`.
O tempo real do chat depende de um terminal separado com `php artisan reverb:start --host=127.0.0.1 --port=8090` no `backend/`.

Como backend e desktop compartilham o mesmo processo Apache/PHP no XAMPP, classes de runtime com o mesmo FQCN entre os dois Laravel podem colidir. O canal desktop deve manter nomes exclusivos para providers, controllers base e services de container que tambem existam no backend.

Para reduzir vazamento de configuracao entre workers compartilhados, os entrypoints web do backend e do desktop recarregam a propria `.env` apenas no SAPI de servidor web. Esse comportamento nao afeta `php artisan`, testes ou outros comandos de console.

## Recuperacao de senha local

- O `backend/.env` usa `MAIL_MAILER=log` por padrao no desenvolvimento local.
- Nesse modo, o backend grava e-mails apenas em `backend/storage/logs/laravel.log`; o fluxo publico de redefinicao agora falha de forma segura em vez de abrir preview local automatico.
- Para que a recuperacao de senha funcione de ponta a ponta, configure um SMTP real no backend central em `Configuracoes > Integracoes > E-mail` ou ajuste `MAIL_MAILER=smtp` com `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` e remetente validos no ambiente.

## Observacao sobre o painel do XAMPP

Se o painel do XAMPP acusar conflito na porta `443`, isso normalmente significa que o proprio painel ainda esta com `ApacheSSL=443` no arquivo `C:\xampp\xampp-control.ini`, mesmo quando o Apache real ja foi ajustado para `8443`.

- O Apache desta arquitetura usa `8443` para SSL, nao `443`.
- O Tomcat, quando presente no painel do XAMPP, usa `8081` nesta maquina para nao colidir com o desktop em `8080`.
- O servico do Windows `iphlpsvc` pode ocupar `443` em algumas maquinas, o que nao afeta o uso de `8443`.
- Se o painel continuar reclamando, abra o XAMPP Control Panel como administrador e confirme `ApacheSSL=8443` e `TomcatHTTP=8081` no `xampp-control.ini`.
- Se aparecer `Failed to open the 'Apache2.4' service` ou `Acesso negado`, o painel nao foi aberto com privilegios administrativos suficientes para controlar o servico do Apache.
- O MySQL deve permanecer como servico do Windows (`mysql`) gerenciado pelo XAMPP Control Panel. Se o painel exibir `MySQL shutdown unexpectedly` mas o backend ja estiver respondendo, finalize quaisquer processos `mysqld.exe` soltos e inicie o servico novamente.
- Se o log do MySQL voltar a mostrar `ibdata1 must be writable`, a causa normalmente e permissao insuficiente no diretorio `C:\xampp\mysql\data` ou um processo iniciado fora do contexto correto do servico. Nesse caso, inicie o XAMPP como administrador e valide o servico `mysql` como `LocalSystem`.
