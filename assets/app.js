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
// ==========================================
// FAIRSHARE NOTIFICATION CENTER
// ==========================================

const notificationRoot = document.querySelector("[data-notification-root]");

if (notificationRoot) {
  const trigger = notificationRoot.querySelector("[data-notification-toggle]");

  const panel = notificationRoot.querySelector("[data-notification-panel]");

  const list = notificationRoot.querySelector("[data-notification-list]");

  const badge = notificationRoot.querySelector("[data-notification-count]");

  const readAllButton = notificationRoot.querySelector(
    "[data-notification-read-all]",
  );

  const csrf = notificationRoot.dataset.csrf;

  const updateBadge = (count) => {
    const total = Number(count) || 0;

    badge.textContent = total > 99 ? "99+" : String(total);
    badge.hidden = total === 0;
  };

  const formatNotificationDate = (value) => {
    if (!value) return "";

    const date = new Date(String(value).replace(" ", "T"));

    if (Number.isNaN(date.getTime())) {
      return String(value);
    }

    return new Intl.DateTimeFormat("id-ID", {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(date);
  };

  const showMessage = (message, className = "notification-empty") => {
    const element = document.createElement("div");
    element.className = className;
    element.textContent = message;
    list.replaceChildren(element);
  };

  const renderNotifications = (notifications) => {
    list.replaceChildren();

    if (!notifications.length) {
      showMessage("Belum ada pesan untuk kamu.");
      return;
    }

    notifications.forEach((notification) => {
      const item = document.createElement("button");
      item.type = "button";
      item.className =
        "notification-item" + (notification.is_read ? "" : " unread");

      item.dataset.notificationId = notification.id;

      const symbol = document.createElement("span");
      symbol.className = "notification-symbol";

      const symbols = {
        info: "i",
        success: "✓",
        warning: "!",
        task: "✓",
        system: "F",
      };

      symbol.textContent = symbols[notification.category] || "i";

      const content = document.createElement("span");
      content.className = "notification-content";

      const title = document.createElement("strong");
      title.textContent = notification.title || "Notifikasi";

      const message = document.createElement("p");
      message.textContent = notification.message || "";

      const meta = document.createElement("small");
      meta.textContent =
        (notification.sender_name || "FairShare") +
        " · " +
        formatNotificationDate(notification.created_at);

      content.append(title, message, meta);
      item.append(symbol, content);

      if (!notification.is_read) {
        const dot = document.createElement("span");
        dot.className = "notification-item-dot";
        item.append(dot);
      }

      list.append(item);
    });
  };

  const loadNotifications = async () => {
    showMessage("Memuat notifikasi...", "notification-loading");

    try {
      const response = await fetch("notifications.php", {
        method: "GET",
        headers: {
          Accept: "application/json",
        },
        credentials: "same-origin",
        cache: "no-store",
      });

      const result = await response.json();

      if (!response.ok || !result.ok) {
        throw new Error(result.message || "Gagal memuat notifikasi.");
      }

      updateBadge(result.unread_count);
      renderNotifications(result.items || []);
    } catch (error) {
      showMessage(error.message || "Notifikasi belum dapat dimuat.");
    }
  };

  const sendNotificationAction = async (action, id = "") => {
    const body = new URLSearchParams({
      csrf: csrf,
      action: action,
      id: id,
    });

    const response = await fetch("notifications.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
        Accept: "application/json",
      },
      credentials: "same-origin",
      body: body,
    });

    const result = await response.json();

    if (!response.ok || !result.ok) {
      throw new Error(result.message || "Aksi notifikasi gagal.");
    }

    await loadNotifications();
  };

  trigger.addEventListener("click", async () => {
    const willOpen = panel.hidden;

    panel.hidden = !willOpen;
    trigger.setAttribute("aria-expanded", String(willOpen));

    if (willOpen) {
      await loadNotifications();
    }
  });

  list.addEventListener("click", async (event) => {
    const item = event.target.closest("[data-notification-id]");

    if (!item || !item.classList.contains("unread")) {
      return;
    }

    try {
      await sendNotificationAction("mark_read", item.dataset.notificationId);
    } catch (error) {
      toast(error.message || "Gagal membaca notifikasi.");
    }
  });

  readAllButton.addEventListener("click", async () => {
    try {
      await sendNotificationAction("mark_all_read");
    } catch (error) {
      toast(error.message || "Gagal menandai notifikasi.");
    }
  });

  document.addEventListener("click", (event) => {
    if (!notificationRoot.contains(event.target) && !panel.hidden) {
      panel.hidden = true;
      trigger.setAttribute("aria-expanded", "false");
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !panel.hidden) {
      panel.hidden = true;
      trigger.setAttribute("aria-expanded", "false");
      trigger.focus();
    }
  });
}
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

// Menu profil (pojok kanan atas): buka/tutup dropdown Keluar
(() => {
  const root = document.querySelector("[data-profile-menu]");
  if (!root) return;
  const trigger = root.querySelector("[data-profile-toggle]");
  const panel = root.querySelector("[data-profile-panel]");
  if (!trigger || !panel) return;

  const setOpen = (open) => {
    panel.hidden = !open;
    trigger.setAttribute("aria-expanded", open ? "true" : "false");
  };

  trigger.addEventListener("click", (event) => {
    event.stopPropagation();
    setOpen(panel.hidden);
  });

  document.addEventListener("click", (event) => {
    if (!root.contains(event.target)) setOpen(false);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !panel.hidden) {
      setOpen(false);
      trigger.focus();
    }
  });
})();
 