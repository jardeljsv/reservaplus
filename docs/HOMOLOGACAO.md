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
