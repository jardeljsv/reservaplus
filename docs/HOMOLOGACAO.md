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

### Três termos com signatários distintos — novas reservas de equipamento
- [ ] Uma nova reserva de equipamento grava `three_terms_v1` no escopo congelado; reservas antigas `two_terms_v1`, `legacy` e salas não são alteradas.
- [ ] **Termo de Responsabilidade**: é emitido automaticamente na solicitação e assinado exclusivamente pelo beneficiário antes da aprovação.
- [ ] O técnico registra a devolução física com equipamentos e acessórios efetivamente recebidos, sem antecipar inspeção nem atribuir aceite ao beneficiário.
- [ ] **Termo de Devolução**: é emitido após o recebimento físico, e somente o beneficiário indicado na reserva é signatário.
- [ ] Mesmo se `return_signature_required` estava desativado, a devolução desta nova modalidade exige a assinatura do beneficiário.
- [ ] O técnico não pode realizar conferência enquanto faltar a assinatura finalizada e íntegra do termo de devolução vigente.
- [ ] O técnico registra testes, condições, ausências e resultado. O registro produz automaticamente **Termo de Conferência Técnica**, cujo único signatário é o inspetor autorizado que registrou o ato.
- [ ] Após a conferência, a interface direciona o técnico ao termo de conferência e à assinatura manuscrita.
- [ ] Antes da assinatura do técnico, a reserva permanece “Devolução em andamento”; somente o último aceite, com itens prontos e acessórios completos, conclui automaticamente e soluciona o chamado.
- [ ] Avarias, quarentena, pendências de acessórios e conferências parciais nunca produzem conclusão automática.
- [ ] Testar dois usuários distintos (beneficiário e técnico), com GLPI/perfis diferentes e acesso restrito às assinaturas do seu papel.
- [ ] Testar usuário com dois papéis acumulados e conferir aceite separado por documento, nunca aceite por papel inventado.
- [ ] Recebimentos adicionais invalidam a versão anterior de devolução e exigem novo aceite vinculado aos fatos atuais.
- [ ] PDFs e registros antigos permanecem íntegros e imutáveis; não atualizar em massa o status ou hashes das reservas antigas.
- [ ] Teste integrado só em banco descartável, autorizado explicitamente; os testes estáticos de CI não substituem homologação funcional.

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
