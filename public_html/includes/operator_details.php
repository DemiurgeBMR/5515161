<li>Наименование: <?php echo htmlspecialchars(OPERATOR_NAME); ?></li>
<li>ИНН: <?php echo htmlspecialchars(OPERATOR_INN); ?></li>
<li>ОГРНИП: <?php echo htmlspecialchars(OPERATOR_OGRNIP); ?></li>
<li>Юридический и почтовый адрес: <?php echo htmlspecialchars(OPERATOR_ADDRESS); ?></li>
<li>Телефон: <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^+0-9]/', '', CONTACT_PHONE)); ?>"><?php echo htmlspecialchars(CONTACT_PHONE); ?></a></li>
