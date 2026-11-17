# Instalação e atualização — Reserva Plus (GLPI 11)

## Compatibilidade e estado

- Linha suportada declarada no plugin: GLPI 11.0.7 até antes de 11.1.0; PHP 8.2 ou superior.
- A ramificação develop contém alterações ainda não certificadas em produção.
- A versão exibida em setup.php permanece 0.7.0-rc3 até a formalização de um novo candidato.
- **Atualização de aceites por papel:** requer migração aditiva de banco (coluna `role` e índice único por documento/usuário/papel). Não é uma atualização exclusivamente visual.

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

## Migração obrigatória para aceites por papel (homologação)

Esta etapa deve ser executada **antes de usar os novos formulários de assinatura**. Uma mesma conta GLPI poderá assinar como beneficiário e, posteriormente, como inspetor com a permissão de inspeção ativa.

1. Criar backup consistente do **banco GLPI inteiro** e do diretório privado dos termos, incluindo a chave de integridade. Guardar os backups fora do GitHub.
2. Atualizar o clone de desenvolvimento e publicar os arquivos alterados na instalação ativa em uma janela controlada. **Não copiar somente o arquivo DocumentService.php**, pois a interface e o esquema precisam ser coerentes.
3. Antes de validar qualquer assinatura, executar, a partir da raiz do GLPI:

       sudo -u www-data php plugins/reservaplus/tools/migrate_role_acceptances.php --apply

   Saída esperada: `OK: role acceptance index updated. Historic documents and signatures preserved.` ou `OK: per-role acceptance schema is already active.`

4. Confirmar a estrutura no MariaDB:

       SHOW COLUMNS FROM glpi_plugin_reservaplus_acceptances LIKE 'role';
       SHOW INDEX FROM glpi_plugin_reservaplus_acceptances WHERE Key_name='signer';

   O índice `signer` deve conter, nesta ordem, `agreements_id`, `users_id` e `role`. Registros históricos mantêm seus `roles_json` originais e recebem `role='legacy'`.

5. Testar um mesmo usuário nos papéis Beneficiário e Inspetor: o primeiro aceite, com perfil solicitante, deve deixar o documento **parcialmente assinado**; o segundo exige o perfil de inspeção e somente então o documento pode ser finalizado.

**Reversão:** não basta restaurar PHP antigo após ter registrado dois aceites para a mesma conta/documento. Para voltar integralmente à versão anterior, restaurar em conjunto os backups de banco, plugin e documentos privados; não reduzir o índice em um banco que já contenha dois aceites independentes.

## Reversão de interface

Se o teste falhar, restaurar somente os arquivos atualizados a partir do backup realizado antes da alteração e limpar o cache. Não desinstalar o plugin nem excluir tabelas. Para mudança de esquema ou documentos, seguir plano de restauração conjunto de banco e armazenamento privado.

## Verificação

- Perfil solicitante: início, minhas solicitações, termos e histórico.
- Perfil aprovador: decisões com e sem autorização, inclusive múltiplos recursos.
- Perfil operacional: entrega, recebimento de acessórios, inspeção e conclusão.
- Confirmar cron, notificação, anexos, logs e regras de roteamento dos tickets.
- Registrar commit, data, ambiente, executor e evidências em HOMOLOGACAO.md.

A instalação em produção permanece condicionada à homologação e ao plano de reversão.
