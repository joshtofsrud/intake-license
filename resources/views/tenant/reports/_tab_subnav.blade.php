{{--
  Shared subnav partial for the Reports tabs.
  Usage:  @include('tenant.reports._tab_subnav', ['active' => 'services'])
  Active values: operations | customers | services | retail | money | staff
--}}
<nav class="rep-toggle" style="margin-bottom: 18px;">
  <a href="{{ route('tenant.reports.index',     []) }}" class="{{ ($active ?? '') === 'operations' ? 'active' : '' }}">Operations</a>
  <a href="{{ route('tenant.reports.customers', []) }}" class="{{ ($active ?? '') === 'customers'  ? 'active' : '' }}">Customers</a>
  <a href="{{ route('tenant.reports.services',  []) }}" class="{{ ($active ?? '') === 'services'   ? 'active' : '' }}">Services</a>
  <a href="{{ route('tenant.reports.retail',    []) }}" class="{{ ($active ?? '') === 'retail'     ? 'active' : '' }}">Retail</a>
  <a href="{{ route('tenant.reports.money',     []) }}" class="{{ ($active ?? '') === 'money'      ? 'active' : '' }}">Money</a>
  <a href="{{ route('tenant.reports.staff',     []) }}" class="{{ ($active ?? '') === 'staff'      ? 'active' : '' }}">Staff</a>
</nav>
