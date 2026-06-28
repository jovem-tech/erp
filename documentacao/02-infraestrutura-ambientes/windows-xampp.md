# Windows + XAMPP

## Objetivo

Subir o backend e a operação local de forma previsível, sem caminhos absolutos espalhados pelo código.

## Regras

- O Apache do XAMPP também reserva a porta `8000` para `C:\xampp\htdocs\sistema-erp\backend\public`.
- O Apache do XAMPP também reserva a porta `8080` para `C:\xampp\htdocs\sistema-erp\frontends\desktop\public`.
- O Apache do XAMPP não deve reservar a porta `3001`; ela fica preferencialmente disponível para o `frontends/mobile` em desenvolvimento.
- O Apache do XAMPP não deve reservar a porta `3002`; ela pertence ao `frontends/chat` em desenvolvimento.
- O Apache do XAMPP não deve reservar a porta `8090`; ela pertence ao processo local do Laravel Reverb.
- O backend deve ler caminhos de `backend/.env.example` e do `.env` real.
- As filas podem usar `sync` enquanto não houver jobs assíncronos.
- O scheduler pode rodar via Task Scheduler com `php artisan schedule:run`.

## Templates

- `infra/windows/apache-vhost.conf`
- `infra/windows/hosts.example`
- `scripts/powershell/validate-dev-env.ps1`

## Observação de operação

O ambiente local não deve servir `C:\xampp\htdocs\sistema-erp` como raiz pública.
O acesso ao backend central deve ser feito por `http://127.0.0.1:8000` via Apache/XAMPP, não por `php artisan serve`.
O acesso ao desktop deve ser feito por `http://127.0.0.1:8080` via Apache/XAMPP, não por `php artisan serve`.
O acesso ao mobile deve ser feito via `pnpm dev` em `frontends/mobile`, preferindo `http://127.0.0.1:3001`; se a porta estiver ocupada no bind IPv4 local (`127.0.0.1`) ou no bind IPv6 usado pelo Next (`::`), o script sobe o Next na próxima porta livre e informa a URL no terminal.
O acesso ao chat deve ser feito via `npm run dev` em `frontends/chat`, usando `http://127.0.0.1:3002`.
O tempo real do chat depende de um terminal separado com `php artisan reverb:start --host=127.0.0.1 --port=8090` no `backend/`.

Como backend e desktop compartilham o mesmo processo Apache/PHP no XAMPP, classes de runtime com o mesmo FQCN entre os dois Laravel podem colidir. O canal desktop deve manter nomes exclusivos para providers, controllers base e services de container que também existam no backend.

Para reduzir vazamento de configuracao entre workers compartilhados, os entrypoints web do backend e do desktop recarregam a propria `.env` apenas no SAPI de servidor web. Esse comportamento nao afeta `php artisan`, testes ou outros comandos de console.

## Observação sobre o painel do XAMPP

Se o painel do XAMPP acusar conflito na porta `443`, isso normalmente significa que o próprio painel ainda está com `ApacheSSL=443` no arquivo `C:\xampp\xampp-control.ini`, mesmo quando o Apache real já foi ajustado para `8443`.

- O Apache desta arquitetura usa `8443` para SSL, não `443`.
- O Tomcat, quando presente no painel do XAMPP, usa `8081` nesta máquina para não colidir com o desktop em `8080`.
- O serviço do Windows `iphlpsvc` pode ocupar `443` em algumas máquinas, o que não afeta o uso de `8443`.
- Se o painel continuar reclamando, abra o XAMPP Control Panel como administrador e confirme `ApacheSSL=8443` e `TomcatHTTP=8081` no `xampp-control.ini`.
- Se aparecer `Failed to open the 'Apache2.4' service` ou `Acesso negado`, o painel não foi aberto com privilégios administrativos suficientes para controlar o serviço do Apache.
- O MySQL deve permanecer como serviço do Windows (`mysql`) gerenciado pelo XAMPP Control Panel. Se o painel exibir `MySQL shutdown unexpectedly` mas o backend já estiver respondendo, finalize quaisquer processos `mysqld.exe` soltos e inicie o serviço novamente.
- Se o log do MySQL voltar a mostrar `ibdata1 must be writable`, a causa normalmente é permissão insuficiente no diretório `C:\xampp\mysql\data` ou um processo iniciado fora do contexto correto do serviço. Nesse caso, inicie o XAMPP como administrador e valide o serviço `mysql` como `LocalSystem`.
