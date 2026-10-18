document.addEventListener("DOMContentLoaded", () => {
  const themeButton = document.querySelector("[data-theme-toggle]");
  const applyTheme = (theme) => {
    document.documentElement.dataset.theme = theme;
    if (themeButton) {
      const dark = theme === "dark";
      themeButton.querySelector(".theme-icon").textContent = dark ? "☀" : "☾";
      themeButton.querySelector(".theme-label").textContent = dark
        ? "Light mode"
        : "Dark mode";
      themeButton.setAttribute(
        "aria-label",
        dark ? "Aktifkan mode terang" : "Aktifkan mode gelap",
      );
    }
  };
  let savedTheme = "light";
  try {
    savedTheme = localStorage.getItem("fairshare-theme") || "light";
  } catch (e) {}
  applyTheme(savedTheme);
  themeButton?.addEventListener("click", () => {
    const next =
      document.documentElement.dataset.theme === "dark" ? "light" : "dark";
    applyTheme(next);
    try {
      localStorage.setItem("fairshare-theme", next);
    } catch (e) {}
  });

  const sidebar = document.getElementById("sidebar");
  document
    .querySelectorAll("[data-menu-toggle]")
    .forEach((b) =>
      b.addEventListener("click", () => sidebar?.classList.toggle("open")),
    );
  document.addEventListener("click", (e) => {
    if (
      sidebar?.classList.contains("open") &&
      !sidebar.contains(e.target) &&
      !e.target.closest("[data-menu-toggle]")
    )
      sidebar.classList.remove("open");
  });
  document.querySelectorAll("[data-copy]").forEach((b) =>
    b.addEventListener("click", async () => {
      try {
        await navigator.clipboard.writeText(b.dataset.copy);
        toast("ID kelompok disalin");
      } catch {
        toast("ID kelompok: " + b.dataset.copy);
      }
    }),
  );
  document
    .querySelectorAll("[data-toast]")
    .forEach((b) => b.addEventListener("click", () => toast(b.dataset.toast)));
  document.querySelectorAll("[data-flash]").forEach((el) => {
    setTimeout(() => {
      el.style.opacity = "0";
      setTimeout(() => el.remove(), 350);
    }, 5000);
  });
});
function toast(message) {
  let el = document.createElement("div");
  el.className = "toast";
  el.textContent = message;
  document.body.appendChild(el);
  requestAnimationFrame(() => el.classList.add("show"));
  setTimeout(() => {
    el.classList.remove("show");
    setTimeout(() => el.remove(), 250);
  }, 2600);
}
