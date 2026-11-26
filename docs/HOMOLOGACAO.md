# Roteiro de homologação — desenvolvimento após 0.7.0-rc3

Estado: checklist de execução, não certificado de aprovação. Ambiente observado no SAUDESV0170: GLPI 11.0.7, PHP 8.3.36, Reserva Plus 0.7.0-rc3. Registrar commit exato e data em cada rodada.

## Verificações de preparação

- [ ] Backup válido do banco de homologação e diretório do plugin
- [ ] Backup dos documentos privados e chave de integridade
- [ ] PHP -l e testes independentes aprovados
- [ ] Cache limpo e revisão de OPcache quando aplicável
- [ ] Perfis solicitante, aprovador e operador disponíveis para testes
- [ ] Regras nativas de ticket e ações automáticas revisadas

## Cenários

### Envio integrado com assinatura antes da aprovação
- [ ] Novo equipamento: botão final mostra Continuar para assinatura; envio cria reserva técnica provisória, chamado e documento sem duplicar registros.
- [ ] Após o envio, a tela abre diretamente o termo do solicitante beneficiário, incluindo PDF original e campo de desenho.
- [ ] Enquanto o termo estiver pendente, lista e detalhe mostram Aguardando assinatura, e a fila de aprovação não apresenta o pedido.
- [ ] O aprovador não vê a opção Autorizar antes da assinatura; tentativa direta na API de ação permanece bloqueada.
- [ ] Depois do aceite e finalização do PDF, redireciona à reserva com mensagem de encaminhamento e status Aguardando aprovação.
- [ ] Reserva feita em nome de outra pessoa: registra a reserva e informa que o beneficiário precisa assinar, sem permitir assinatura pelo solicitante intermediário.
- [ ] Reserva de sala sem exigência antecipada: preserva Enviar solicitação e processo anterior.
- [ ] Recarregar a tela ou repetir a solicitação não duplica chamado, documento ou assinatura.

### Campos de Unidade e Lotação (integração com GLPI da SES-DF)
- [ ] Na etapa **Como os recursos serão utilizados?**, depois de **Usuário responsável pelo uso** e antes de **Finalidade do uso**, aparecem dois campos obrigatórios: **Unidade / Localização GLPI** e **Lotação**.
- [ ] **Unidade** usa as localizações nativas cadastradas em `glpi_locations` (Administração > Localizações); o usuário seleciona um ID válido no contexto de entidade.
- [ ] **Lotação** usa exclusivamente todos os descendentes de `glpi_groups.id = 61` (LOTAÇÃO), em qualquer profundidade. O grupo raiz 61 não é opção de resposta.
- [ ] Usuário não consegue avançar sem escolher ambos; o backend rejeita IDs forjados, grupos fora da árvore e localizações não disponíveis na entidade.
- [ ] A revisão exibe ambos os nomes; o snapshot da reserva conserva `locations_id`, `lotation_groups_id`, nome da unidade e nome da lotação, preservando histórico e PDFs se o cadastro for alterado.
- [ ] Ao criar o chamado GLPI, `glpi_tickets.locations_id` recebe o ID escolhido em **Unidade**. Ao utilizar chamado existente, o campo é atualizado após validação das permissões nativas.
- [ ] A lotação identifica o setor administrativo, mas não altera automaticamente o grupo técnico atribuído ao chamado.
- [ ] Não existe migração de reservas antigas; os dados gravados e documentos assinados permanecem inalterados.
- [ ] Validar seleção no catálogo, reservas de sala/equipamento, criação de chamado, chamado existente e impressão de ambos os campos no PDF.
- [ ] Somente em testes integrados com base GLPI **descartável** pode ser utilizado o override `RESERVAPLUS_LOTATION_ROOT_GROUP_ID`; em SES-DF a raiz padrão é 61.

### Devolução unificada — termo único assinado pelo técnico (novas reservas de equipamento)
- [ ] Novas reservas exclusivamente de equipamento recebem o marcador congelado `unified_return_v1`; anteriores (`legacy`, `two_terms_v1` e `three_terms_v2`) não são alteradas.
- [ ] O beneficiário assina o **Termo de Responsabilidade pelo Uso** antes da aprovação.
- [ ] Depois da retirada física, beneficiário/solicitante ou técnico autorizado pode acionar **Iniciar devolução**, sem confirmar recebimento automaticamente.
- [ ] O profissional técnico/cedente, com permissões de recebimento **e** inspeção, acessa uma única operação: **Receber devolução e realizar conferência**.
- [ ] Nesta operação única, informa itens efetivamente recebidos, acessórios, entregador, testes, condições, avarias, observações e resultado.
- [ ] Recebimento e inspeção são gravados juntos sob uma transação; falha de validação não deve registrar nenhum deles parcialmente.
- [ ] O sistema gera automaticamente somente **Termo de Devolução e Conferência Técnica** (`return_inspection`), com um signatário por conta técnica responsável pela inspeção; o PDF inclui recebimento físico e acessórios.
- [ ] O técnico é direcionado para assinar o PDF original; após a assinatura, o PDF final pode ser visualizado e baixado também pelo beneficiário como comprovante.
- [ ] Não é emitido `return_receipt` no novo fluxo, e não há assinatura do beneficiário na devolução.
- [ ] A assinatura do técnico conclui a reserva automaticamente somente se todos os itens estiverem aprovados, sem pendências de acessórios e com documentos íntegros.
- [ ] Caso não seja possível solucionar o chamado imediatamente, a sincronização fica registrada em fila para tentativa autorizada/cron.
- [ ] Conferência parcial, quarentena, manutenção e acessórios faltantes permanecem rastreáveis e não concluem automaticamente.
- [ ] Validar em perfis distintos de beneficiário e técnico/cedente; uma conta sem permissão técnica não pode executar a conferência.
- [ ] Reservas antigas e seus PDFs/hash não são alterados; usar uma **reserva nova** para homologar.
- [ ] Validar repetição, CSRF, revisão desatualizada, assinatura por papel correto, PDF original/final, integridade e histórico.
- [ ] Rodar a integração com banco descartável explicitamente autorizado; testes unitários da CI não substituem a homologação.

### Nova assinatura de equipamento — ordem obrigatória
- [ ] Nova reserva de equipamento gera termo de cessão antes da aprovação (mesmo se o recurso tinha configuração anterior de assinatura após aprovação).
- [ ] Aprovação na aba Reserva Plus fica bloqueada até assinatura e finalização do termo da solicitação.
- [ ] Notificação de aprovação nativa não é enviada antes do termo concluído.
- [ ] Após assinar, a aprovação é liberada e a entrega continua exigindo disponibilidade, autorização e janela de horário.
- [ ] PDF original mostra seções equipamento, requerente, período e responsabilidade e não afirma recebimento físico anterior à entrega.
- [ ] Formulário exige assinatura manuscrita de mouse ou toque para novos termos de equipamento e impede desenho vazio.
- [ ] PDF final contém assinatura desenhada e comprovante eletrônico autenticado, preservando PDF original e SHA-256.
- [ ] Mesma pessoa só assina uma vez por documento; mudança de perfil não repete o aceite.
- [ ] Salas, termos históricos e devoluções mantêm seus fluxos anteriores.
- [ ] Mudança de datas cria revisão válida e exige novo termo, sem perder a evidência histórica.
- [ ] Validar também no celular e no Firefox, Chrome e Edge.

- [ ] Solicitação nova cria uma reserva e um ticket nativo
- [ ] Catálogo e seleção não criam ticket antes de enviar
- [ ] Aprovador elegível vê Autorizar/Rejeitar no Painel, Solicitações e detalhe
- [ ] Aprovador não elegível vê apenas ações permitidas
- [ ] Aprovação por recurso respeita grupos congelados e validação nativa
- [ ] Rejeição exige motivo e gera histórico sem duplicidade
- [ ] Clique repetido e revisão obsoleta são tratados com segurança
- [ ] Alterar período só aparece a quem pode e revalida disponibilidade
- [ ] Cancelamento exige motivo, sem cancelar custódia já entregue
- [ ] Solicitante vê Acompanhar, Termos, Reservar novamente e Cancelar apenas quando permitido
- [ ] Reservar novamente não copia ticket, datas, aprovações e assinaturas
- [ ] Assinatura abre documento correto e mantém exigências de aceite
- [ ] Migração de banco aplicada: coluna `role` e índice único `agreements_id, users_id, role`; aceites históricos `legacy` preservados
- [ ] Mesmo usuário como Beneficiário e Inspetor/Recebedor: existe apenas um botão Assinar termo em qualquer perfil autorizado
- [ ] Após o aceite do beneficiário, não é solicitado segundo aceite à mesma conta; se houver outras pessoas signatárias, o documento permanece pendente até seus aceites
- [ ] Perfil sem direito de inspeção/recebimento não pode assinar declarando o papel técnico; pode assinar como Beneficiário quando designado
- [ ] Com perfil de Inspetor/Recebedor autorizado, se ainda não assinou, o mesmo usuário registra um único aceite com seu papel técnico; após assinar, o botão desaparece em ambos os perfis
- [ ] O PDF final apresenta um único comprovante para o signatário, com papel efetivamente utilizado, sem perder registros técnicos de devolução e conferência
- [ ] Uma assinatura já registrada não é repetida ao recarregar ou tentar novamente
- [ ] Documento histórico assinado antes da migração continua verificável e válido

- [ ] Entrega, devolução parcial, acessórios e inspeção não podem ser ignorados
- [ ] Tela mobile e desktop exibem botões, cores, contraste e formulários
- [ ] Notificações e tarefas automáticas funcionam em CLI
- [ ] Cancelar/desfazer alterações preserva dados e histórico

## Evidências por teste

Registrar: commit SHA, identidade/perfil do ator, entidade, IDs da reserva e ticket, data/hora, passos, resultado esperado, resultado observado, captura/log sem credenciais e decisão aprovar/reprovar.

## Critério de liberação

Sem erros de permissão, dupla alocação, perda de registros, desvio de regras do GLPI ou bypass de termo/inspeção. Testes independentes de PHP e GitHub Actions não substituem validação integrada em ambiente real. Atualizar o status dos cenários após a execução; este arquivo sozinho não comprova homologação.
