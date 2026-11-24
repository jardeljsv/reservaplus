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

### Devolução definitiva — conferência assinada antes do comprovante (novas reservas de equipamento)
- [ ] Novas reservas exclusivamente de equipamento congelam `three_terms_v2`; reservas antigas e salas preservam o modelo anterior.
- [ ] Beneficiário assina **Termo de Responsabilidade pelo Uso** na solicitação, antes de liberar a aprovação.
- [ ] Com reserva em uso, beneficiário/solicitante ou técnico autorizado consegue clicar **Iniciar devolução** uma única vez; esse ato não indica recebimento físico.
- [ ] Técnico autorizado registra **Receber devolução**, identifica o entregador e discrimina acessórios físicos.
- [ ] Técnico registra testes e divergências em **Conferir devolução** após recebimento físico. Uma conferência com acessórios pendentes não pode liberar equipamento para uso.
- [ ] Após a conferência o sistema emite **Termo de Conferência Técnica**, exigindo assinatura eletrônica manuscrita do inspetor real.
- [ ] Antes do PDF técnico íntegro e assinado, o sistema **não** emite nem permite assinar o comprovante do beneficiário.
- [ ] Após **todos** os equipamentos recebidos e conferidos, com a conferência técnica assinada, o sistema gera **Termo de Devolução**, incluindo equipamentos, acessórios, resultados e ressalvas da inspeção.
- [ ] Beneficiário assina seu comprovante de devolução e pode baixar o PDF final, sem qualquer assinatura de recebedor/inspetor nesse terceiro documento.
- [ ] Somente após os três documentos finalizados, os itens prontos e acessórios conciliados, a assinatura final conclui automaticamente a reserva.
- [ ] A solução nativa do chamado segue fila auditável separada quando o beneficiário não possui permissão GLPI de solução; testar execução por usuário autorizado/cron e eventual estado de integração pendente.
- [ ] Conferência parcial, quarentena, manutenção e acessórios faltantes não concluem automaticamente; documentar ressalvas e exigir tratamento real.
- [ ] Verificar com **duas identidades reais**: beneficiário sem direitos técnicos e inspetor com direitos técnicos. Uma pessoa com ambos os papéis deve assinar cada documento apropriado separadamente.
- [ ] Documentos antigos (incluindo reservas #17 e #22) não são refeitos nem têm assinatura/hash alterado.
- [ ] Validar edição, repetição, CSRF, mudanças de revisão, termo vigente, PDF de origem/final e trilha de auditoria.
- [ ] Rodar `tests/integration_workflow.php` somente em uma base descartável habilitada explicitamente; GitHub Actions não cobre o GLPI real.

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
