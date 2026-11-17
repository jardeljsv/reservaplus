# Guia do aprovador — Reserva Plus

## Onde aprovar

- Painel > Aprovações pendentes: ações compactas nos cartões elegíveis.
- Solicitações: ações compactas na coluna Ações.
- Acompanhar reserva: botão maior Autorizar recursos e opção Rejeitar solicitação.
- Aba Reserva Plus do ticket: fluxo completo autorizado.

## Decisões

- Autorizar (verde): confirma a decisão apenas dos recursos sob autoridade do usuário; há confirmação antes do envio.
- Rejeitar (vermelho): exige justificativa e encerra a solicitação conforme as regras.
- Alterar período (azul): somente quando a política e as permissões permitem, antes da entrega.
- Cancelar (amarelo): somente conforme situação e direitos; exige motivo.

## Aprovação de múltiplos recursos

Uma solicitação pode incluir recursos com diferentes conjuntos de aprovadores. Cada ator decide somente linhas elegíveis. A reserva inteira só é alocada quando todos os recursos obrigatórios tiverem decisão positiva e permanecerem disponíveis. Decisões parciais não significam aprovação integral.

## Requisitos de permissão

Aparecer no grupo responsável, ser custodiante ou ter acesso ao ticket não concede automaticamente direito de aprovação. O plugin verifica o direito próprio, entidade, grupo/usuário elegível e a permissão nativa de validação de tickets no GLPI. O conjunto de aprovadores é registrado no momento da solicitação.

## Segurança e auditoria

Os botões rápidos usam o mesmo serviço de negócio das telas detalhadas, incluindo token de comando, verificação de revisão, controles de concorrência e histórico nativo. Se a solicitação mudou, atualizar a página antes de tentar novamente. Não utilizar alterações diretas em SQL ou API genérica para contornar as validações.
