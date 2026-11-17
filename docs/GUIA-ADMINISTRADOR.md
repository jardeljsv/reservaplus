# Guia do administrador — Reserva Plus

## Cadastro e organização

- Definir entidade, tipo do recurso, responsáveis, grupo de tickets, equipe gestora, custodiante e elegibilidade dos solicitantes.
- Configurar políticas de duração, aprovação, assinatura e disponibilidade.
- Os grupos padrão de aprovação podem ser definidos por entidade e tipo; um recurso pode substituir os padrões com conjunto próprio de grupos/usuários.
- Recursos incorporados do GLPI devem manter identidade e permissões nativas. Verificar reservas existentes antes de vincular.

## Tickets

Uma solicitação nova cria um ticket de requisição nativo e o vincula ao fluxo. As regras do GLPI podem alterar roteamento e estado, mas operações incompatíveis são bloqueadas. Recursos de grupos responsáveis distintos não podem ser combinados em uma mesma solicitação nesta versão.

## Painel operacional

Filas: aprovações, entregas, devoluções atrasadas, conferência, itens em uso, assinaturas e integrações pendentes. Os valores dependem do perfil e da entidade ativa. Contagens e listas devem ser comparadas em homologação.

## Ações rápidas

Painel e Solicitações possuem botões compactos autorizados pelo contexto. A tela de detalhes tem controles maiores. A apresentação não altera as regras de negócio. Na interface simplificada, disponibilizar somente atalhos para solicitações próprias (acompanhar, termos, reservar novamente e cancelar quando aplicável).

## Tarefas automáticas

ExpireHolds e DeliverOutbox dependem de ações automáticas ativas e do agendador CLI do GLPI. Verificar logs e entrega de notificações. A versão atual não implementa lembretes programados de devolução nem prorrogação de empréstimo já entregue.

## Operação segura

Antes de cada atualização: backup do banco e documentos privados, comparação de arquivos, revisão de permissões e execução dos testes disponíveis. Manter versionamento de documentos e a chave de integridade fora do repositório.

## Limites da linha em desenvolvimento

A aprovação em lote, o dashboard gerencial avançado, os lembretes configuráveis e a prorrogação de empréstimos ativos continuam planejados; não anunciar essas funcionalidades como concluídas. O suporte declarado permanece na linha GLPI 11.0.
