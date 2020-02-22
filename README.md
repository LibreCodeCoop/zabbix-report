# Relatórios Zabbix SLA

Geração de relatório de SLA para o Zabbix

## Instalação

Baixe o arquivo zip da versão mais recente do projeto **[aqui](https://github.com/lyseontech/zabbix-report/releases/latest)**.

Descompacte o projeto onde irá rodar a aplicação

### Utilizando Docker

Copie o arquivo `.env.example` para `.env`.

> **OBS¹**: Caso vá utilizar com um banco de dados externo, remova o serviço `db` do arquivo `docker-compose.yml` e configure o arquivo `.env` com as credenciais de acesso do banco.

> **OBS²**: Caso utilize um banco de dados na rede do Docker, o volume é persistido na pasta `.docker/volumes/data` e para levantar a aplicação com um dump de banco novo, certifique-se de que a pasta `.docker/volumes/data` não exista e coloque o dump novo na pasta `.docker/volumes/dump`.

Execute o comando que segue na raiz do projeto:

```bash
docker-compose up
```

### Utilizando servidor built-in do PHP

Copie o arquivo `.env.example` para `.env`.

> **OBS**: lembre-se de definir as credenciais de banco corretamente.

Execute o comando que segue na raiz do projeto:

```bash
php bin/console doctrine:migrations:migrate
php -S localhost:8000
```

A migration é preciso ser executada apenas uma vez.

## Geração de release

Clone o projeto, execute `composer install` e rode o comando que segue para
criar o pacote da nova release:

```bash
zip -r zabbix-report.zip . -x '.docker/volumes/*' 'var/*' '*.sql' '.vscode/*' '.git/*' '.env'
```

> **OBS**: Lembre-se de desativar o debug e habilitar o cache do twig `config/packages/twig.yaml`

## Configuração

Os arquivos de configuração encontram-se na pasta `config`.

### Configuração de filtros de exclusão

Edite o arquivo `config/dead_dates.yaml` para informar os filtros de exclusão.

#### weekday
Exclui dias da semana.

Para o filtro weekday, 0 = domingo

#### ignoredEvents
Exclui eventos do relatório. Utilize o relatório descritivo para identificar os dias dos eventos. No relatório descritivo, a coluna recorrente indica eventos que duram mais de um dia.

#### notWorkDay
Dias que não se trabalha, feriados, pontos facultativos.

#### startNotWorkTime & endNotWorkTime
Início do horário de não trabalho e fim do horário de não trabalho. Se o expediente é de 9 às 18h, o início do horário de não trabalho é às `18:00:00` e o fim é às `09:00:00`.