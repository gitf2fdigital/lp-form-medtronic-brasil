(function () {
  "use strict";

  /* ==========================================================
     CONFIG
     - submitEndpoint    : URL do handler PHP no servidor
     - successRedirectUrl: URL de redirecionamento após envio bem-sucedido
     As credenciais RD Station e e-mail ficam APENAS no submit.php (servidor).
  ========================================================== */
  var CONFIG = {
    submitEndpoint:      "./submit.php",
    successRedirectUrl:  "https://info.medtronicdiabetes.com/suporte_ao_usuario"
  };

  /* ==========================================================
     REFERÊNCIAS AO DOM
  ========================================================== */
  var form = document.getElementById("mdtForm");
  var pages = form.querySelectorAll(".mdt-page");
  var totalPages = pages.length;
  var currentPage = 1;

  var backBtn = document.getElementById("mdtBackBtn");
  var nextBtn = document.getElementById("mdtNextBtn");
  var submitBtn = document.getElementById("mdtSubmitBtn");
  var stepInfo = document.getElementById("mdtStepInfo");
  var progressBars = document.querySelectorAll(".mdt-progress-bar");

  var stepCalibracao = document.getElementById("step-calibracao");
  var stepDias = document.getElementById("step-dias");
  var stepGlicemia = document.getElementById("step-glicemia");
  var stepAtualizacao = document.getElementById("step-atualizacao");
  var blockedCalibracao = document.getElementById("blocked-calibracao");
  var blockedAtualizacao = document.getElementById("blocked-atualizacao");
  var submitErrorEl = document.getElementById("mdtSubmitError");

  var state = {
    modelo: null,
    tipo: null,
    removeu: null,
    dias: null,
    glicemia: null,
    atualiz3h: null,
    eligible: false
  };

  /* ==========================================================
     HELPERS
  ========================================================== */
  function show(el) { if (el) el.classList.add("active"); }
  function hide(el) { if (el) el.classList.remove("active"); }
  function showB(el) { if (el) el.classList.add("visible"); }
  function hideB(el) { if (el) el.classList.remove("visible"); }

  function updateProgress() {
    for (var i = 0; i < progressBars.length; i++) {
      if (i < currentPage) progressBars[i].classList.add("active");
      else progressBars[i].classList.remove("active");
    }
    stepInfo.textContent = "Etapa " + currentPage + " de " + totalPages;
  }

  function showPage(n) {
    currentPage = n;
    for (var i = 0; i < pages.length; i++) {
      if (Number(pages[i].getAttribute("data-page")) === n) {
        pages[i].classList.add("active");
      } else {
        pages[i].classList.remove("active");
      }
    }
    backBtn.style.display = n === 1 ? "none" : "";
    nextBtn.style.display = n === totalPages ? "none" : "";
    submitBtn.style.display = n === totalPages ? "" : "none";
    updateProgress();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  /* ==========================================================
     RESET dos sub-passos quando troca o tipo de problema
  ========================================================== */
  function resetSubFlow() {
    state.removeu = null;
    state.dias = null;
    state.glicemia = null;
    state.atualiz3h = null;
    state.eligible = false;
    hide(stepCalibracao);
    hide(stepDias);
    hide(stepGlicemia);
    hide(stepAtualizacao);
    hideB(blockedCalibracao);
    hideB(blockedAtualizacao);
    ["removeu_sensor", "dias_uso", "glicemia_3h", "atualizacao_3h"].forEach(function (n) {
      document.querySelectorAll('input[name="' + n + '"]').forEach(function (x) { x.checked = false; });
    });
  }

  /* ==========================================================
     AVALIAÇÃO DO FLUXO CONDICIONAL (página 2)
  ========================================================== */
  function evaluate() {
    hideB(blockedCalibracao);
    hideB(blockedAtualizacao);
    state.eligible = false;

    if (!state.tipo) return;

    if (state.tipo === "sangue" || state.tipo === "trocar") {
      state.eligible = true;
      return;
    }

    if (state.tipo === "calibracao") {
      show(stepCalibracao);
      if (!state.removeu) return;
      if (state.removeu === "sim") { state.eligible = true; return; }
      show(stepDias);
      if (!state.dias) return;
      show(stepGlicemia);
      if (!state.glicemia) return;
      if (state.glicemia === "nao") { showB(blockedCalibracao); return; }
      state.eligible = true;
      return;
    }

    if (state.tipo === "atualizacao") {
      show(stepAtualizacao);
      if (!state.atualiz3h) return;
      if (state.atualiz3h === "nao") { showB(blockedAtualizacao); return; }
      state.eligible = true;
      return;
    }
  }

  /* ==========================================================
     LISTENERS DOS RADIOS
  ========================================================== */
  document.querySelectorAll('input[name="modelo_sensor"]').forEach(function (r) {
    r.addEventListener("change", function () { state.modelo = this.value; });
  });

  document.querySelectorAll('input[name="tipo_problema"]').forEach(function (r) {
    r.addEventListener("change", function () {
      state.tipo = this.value;
      resetSubFlow();
      evaluate();
    });
  });

  document.querySelectorAll('input[name="removeu_sensor"]').forEach(function (r) {
    r.addEventListener("change", function () {
      state.removeu = this.value;
      state.dias = null;
      state.glicemia = null;
      hide(stepDias);
      hide(stepGlicemia);
      document.querySelectorAll('input[name="dias_uso"]').forEach(function (x) { x.checked = false; });
      document.querySelectorAll('input[name="glicemia_3h"]').forEach(function (x) { x.checked = false; });
      evaluate();
    });
  });

  document.querySelectorAll('input[name="dias_uso"]').forEach(function (r) {
    r.addEventListener("change", function () {
      state.dias = this.value;
      state.glicemia = null;
      hide(stepGlicemia);
      document.querySelectorAll('input[name="glicemia_3h"]').forEach(function (x) { x.checked = false; });
      evaluate();
    });
  });

  document.querySelectorAll('input[name="glicemia_3h"]').forEach(function (r) {
    r.addEventListener("change", function () {
      state.glicemia = this.value;
      evaluate();
    });
  });

  document.querySelectorAll('input[name="atualizacao_3h"]').forEach(function (r) {
    r.addEventListener("change", function () {
      state.atualiz3h = this.value;
      evaluate();
    });
  });

  /* ==========================================================
     MÁSCARAS
  ========================================================== */
  document.getElementById("cpf").addEventListener("input", function () {
    this.value = this.value.replace(/\D/g, "").substring(0, 11);
  });

  document.getElementById("telefone").addEventListener("input", function () {
    var v = this.value.replace(/\D/g, "");
    this.value = v.length <= 10
      ? v.replace(/(\d{2})(\d{4})(\d{0,4})/, "($1) $2-$3").replace(/-$/, "")
      : v.replace(/(\d{2})(\d{5})(\d{0,4})/, "($1) $2-$3").replace(/-$/, "");
  });

  document.getElementById("cep").addEventListener("input", function () {
    var v = this.value.replace(/\D/g, "");
    this.value = v.replace(/(\d{5})(\d{0,3})/, "$1-$2").replace(/-$/, "");
    if (v.length === 8) fetchCEP(v);
  });

  function fetchCEP(cep) {
    fetch("https://viacep.com.br/ws/" + cep + "/json/")
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.erro) {
          var end = document.getElementById("endereco");
          if (end && !end.value) end.value = d.logradouro + (d.bairro ? ", " + d.bairro : "");
          var cid = document.getElementById("cidade");
          if (cid && !cid.value) cid.value = d.localidade;
          var sel = document.getElementById("estado");
          for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === d.uf) { sel.selectedIndex = i; break; }
          }
        }
      })
      .catch(function () { });
  }

  /* ==========================================================
     VALIDAÇÃO POR ETAPA
  ========================================================== */
  function clearErrors(scope) {
    (scope || form).querySelectorAll(".invalid").forEach(function (e) { e.classList.remove("invalid"); });
    (scope || form).querySelectorAll(".mdt-error-msg").forEach(function (e) { e.remove(); });
  }

  function markError(el, msg) {
    if (!el) return;
    el.classList.add("invalid");
    var s = document.createElement("span");
    s.className = "mdt-error-msg";
    s.textContent = msg;
    if (el.parentNode) el.parentNode.appendChild(s);
  }

  function validatePage(n) {
    var page = form.querySelector('.mdt-page[data-page="' + n + '"]');
    clearErrors(page);
    var valid = true;

    if (n === 1) {
      if (!state.modelo) {
        markError(page.querySelector('input[name="modelo_sensor"]'), "Selecione o modelo do sensor.");
        valid = false;
      }
    }

    if (n === 2) {
      if (!state.tipo) {
        markError(page.querySelector('input[name="tipo_problema"]'), "Selecione o tipo de problema.");
        return false;
      }
      if (!state.eligible) {
        if (blockedCalibracao.classList.contains("visible") || blockedAtualizacao.classList.contains("visible")) {
          return false;
        }
        markError(page.querySelector('input[name="tipo_problema"]'), "Responda às perguntas para prosseguir.");
        return false;
      }
    }

    if (n === 3) {
      var requiredFields = page.querySelectorAll("[required]");
      requiredFields.forEach(function (f) {
        if (f.type === "radio") {
          var name = f.name;
          if (!page.querySelector('input[name="' + name + '"]:checked') && !f.dataset._checked) {
            markError(f, "Selecione uma opção.");
            f.dataset._checked = "1";
            valid = false;
          }
        } else if (!f.value.trim()) {
          markError(f, "Campo obrigatório.");
          valid = false;
        }
      });
      if (!page.querySelector('input[name="modelo_bomba"]:checked')) {
        markError(page.querySelector('input[name="modelo_bomba"]'), "Selecione o modelo da bomba.");
        valid = false;
      }
      var cpfEl = document.getElementById("cpf");
      if (cpfEl.value.replace(/\D/g, "").length !== 11) {
        markError(cpfEl, "Informe os 11 dígitos do CPF.");
        valid = false;
      }
      page.querySelectorAll("[data-_checked]").forEach(function (e) { delete e.dataset._checked; });
    }

    if (n === 4) {
      ["analiseEquipe", "autorizaContato", "aceitaTermos"].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && !el.checked) {
          markError(el, "É necessário aceitar para continuar.");
          valid = false;
        }
      });
    }

    if (!valid) {
      var firstInvalid = page.querySelector(".invalid");
      if (firstInvalid) firstInvalid.scrollIntoView({ behavior: "smooth", block: "center" });
    }
    return valid;
  }

  /* ==========================================================
     NAVEGAÇÃO
  ========================================================== */
  nextBtn.addEventListener("click", function () {
    if (!validatePage(currentPage)) return;
    if (currentPage < totalPages) showPage(currentPage + 1);
  });

  backBtn.addEventListener("click", function () {
    if (currentPage > 1) showPage(currentPage - 1);
  });

  /* ==========================================================
     SUBMIT
  ========================================================== */
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    if (!validatePage(4)) return;
    for (var i = 1; i <= totalPages; i++) {
      if (!validatePage(i)) {
        showPage(i);
        return;
      }
    }
    submitForm();
  });

  /* ----------------------------------------------------------
     submitForm — envia JSON para submit.php (servidor)
     O PHP cuida do RD Station OAuth e do e-mail de atendimento.
  ---------------------------------------------------------- */
  function submitForm() {
    var payload = {};
    new FormData(form).forEach(function (v, k) { payload[k] = v; });
    payload.modelo_sensor = state.modelo;
    payload.tipo_problema = state.tipo;

    submitBtn.disabled = true;
    submitBtn.textContent = "Enviando…";
    if (submitErrorEl) submitErrorEl.classList.remove("visible");

    fetch(CONFIG.submitEndpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.success) {
          showSuccess();
        } else {
          throw new Error(data.message || "Erro desconhecido.");
        }
      })
      .catch(function (err) {
        console.error("[submit.php]", err);
        submitBtn.disabled = false;
        submitBtn.textContent = "Submeter pedido";
        if (submitErrorEl) submitErrorEl.classList.add("visible");
      });
  }

  function showSuccess() {
    form.style.display = "none";
    document.querySelector(".mdt-nav").style.display = "none";
    var s = document.getElementById("mdtSuccess");
    s.style.display = "block";
    s.scrollIntoView({ behavior: "smooth" });
    progressBars.forEach(function (b) { b.classList.add("active"); });

    /* Redireciona para a página de suporte após 2,5 s */
    setTimeout(function () {
      window.location.href = CONFIG.successRedirectUrl;
    }, 2500);
  }

  /* ==========================================================
     INIT
  ========================================================== */
  showPage(1);
})();
