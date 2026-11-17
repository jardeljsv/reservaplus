# Instalação e atualização — Reserva Plus (GLPI 11)

## Compatibilidade e estado

- Linha suportada declarada no plugin: GLPI 11.0.7 até antes de 11.1.0; PHP 8.2 ou superior.
- A ramificação develop contém alterações ainda não certificadas em produção.
- A versão exibida em setup.php permanece 0.7.0-rc3 até a formalização de um novo candidato.
- Não é necessário reinstalar nem alterar o esquema do banco para as últimas melhorias exclusivamente de interface.

## Pré-requisitos

1. Confirmar que o servidor é de homologação e registrar versões de GLPI, PHP e MariaDB.
2. Manter backup verificável do banco GLPI, do diretório do plugin e dos documentos privados.
3. Preservar em conjunto o diretório de termos do plugin e a chave privada integrity-v1.key; não armazená-la no GitHub.
4. Confirmar o agendador GLPI em modo CLI, perfis, grupos de aprovação, e-mail e regras de tickets.

## Atualização controlada em homologação

O repositório não deve ser clonado diretamente por cima da instalação ativa. No SAUDESV0170 já foi usada uma cópia separada em /home/03180214120/reservaplus-develop.

    cd /home/03180214120/reservaplus-develop
    git fetch origin
    git pull --ff-only origin develop
    git log -1 --oneline

Conferir as diferenças antes de instalar:

    diff -qr /var/www/html/glpi/plugins/reservaplus /home/03180214120/reservaplus-develop --exclude=.git --exclude=.github

Para alterações somente de interface, atualizar apenas os arquivos relacionados, após validar os testes. Exemplo para as ações rápidas atuais:

    cd /var/www/html/glpi
    for arquivo in src/BookingView.php src/TechnicianDashboard.php front/booking.action.php css/workflow.css public/css/workflow.css css/dashboard.css public/css/dashboard.css; do
        install -o www-data -g www-data -m 644 "/home/03180214120/reservaplus-develop/$arquivo" "plugins/reservaplus/$arquivo"
    done
    sudo -u www-data php bin/console cache:clear

Executar também php -l nos arquivos atualizados. Quando houver alterações de SQL, setup.php, dependências ou hooks, usar procedimento de atualização integral testado, com backup do banco e instalação pelo mecanismo nativo do GLPI. Não utilizar este exemplo parcial para essa situação.

## Reversão de interface

Se o teste falhar, restaurar somente os arquivos atualizados a partir do backup realizado antes da alteração e limpar o cache. Não desinstalar o plugin nem excluir tabelas. Para mudança de esquema ou documentos, seguir plano de restauração conjunto de banco e armazenamento privado.

## Verificação

- Perfil solicitante: início, minhas solicitações, termos e histórico.
- Perfil aprovador: decisões com e sem autorização, inclusive múltiplos recursos.
- Perfil operacional: entrega, recebimento de acessórios, inspeção e conclusão.
- Confirmar cron, notificação, anexos, logs e regras de roteamento dos tickets.
- Registrar commit, data, ambiente, executor e evidências em HOMOLOGACAO.md.

A instalação em produção permanece condicionada à homologação e ao plano de reversão.
